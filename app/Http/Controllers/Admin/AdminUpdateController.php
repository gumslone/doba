<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Maintenance\Backups;
use App\Support\Maintenance\HealthCheck;
use App\Support\Maintenance\Updater;
use App\Support\Version;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The update page (§16).
 *
 * The reason this exists at all: the people running Doba are hoteliers on
 * shared hosting with no shell. An update path that assumes SSH is an
 * update path most of them cannot take, which in practice means they stop
 * updating — including past the security fixes.
 *
 * It runs the same Updater as `php artisan doba:update`, so the two can
 * never drift apart in the step that matters.
 */
class AdminUpdateController extends Controller
{
    public function index(Updater $updater, Backups $backup, HealthCheck $health): View
    {
        $checks = $health->all();

        return view('admin.update.index', [
            'version' => Version::current(),
            'pending' => $updater->pendingMigrations(),
            'backupSupported' => $backup->isSupported(),
            'backupReason' => $backup->unsupportedReason(),
            'backups' => $backup->sets(),
            // Shown above the button, not discovered by pressing it. A
            // hotelier whose host is a PHP version behind should read that
            // sentence before the update, not in the wreckage after one.
            'checks' => $checks,
            'healthy' => HealthCheck::passed($checks),
        ]);
    }

    public function run(Request $request, Updater $updater, Backups $backup): RedirectResponse
    {
        $request->validate([
            // Typed out in full, because this migrates a live hotel's
            // reservations and a mis-click should not be able to start it.
            'confirm' => ['required', 'in:UPDATE'],
        ]);

        if (! $backup->isSupported()) {
            return back()->with('update_error', __('admin.update_no_backup', [
                'reason' => (string) $backup->unsupportedReason(),
            ]));
        }

        $result = $updater->run();

        return redirect('/admin/update')->with([
            'update_checks' => $result->failedChecks,
            'update_result' => $result->steps,
            'update_ok' => $result->ok,
            'update_error' => $result->error,
            'update_restore' => $result->restoreCommand,
        ]);
    }

    /**
     * Take a snapshot without updating.
     *
     * Worth its own button: "back up before I change the rates" is a
     * thing a hotelier wants, and it is the same one click they already
     * know from the update flow.
     */
    public function backup(Backups $backup): RedirectResponse
    {
        if (! $backup->isSupported()) {
            return back()->with('update_error', __('admin.update_no_backup', [
                'reason' => (string) $backup->unsupportedReason(),
            ]));
        }

        $set = $backup->createSet();
        $backup->prune();

        $steps = [__('admin.backup_taken', ['name' => basename($set['database'])])];

        if ($set['uploads'] !== null) {
            $steps[] = __('admin.backup_uploads_taken');
        } elseif ($set['uploads_error'] !== null) {
            // Surfaced rather than swallowed: a hotelier who believes their
            // photos are backed up when they are not finds out at the worst
            // possible moment.
            $steps[] = __('admin.backup_uploads_failed', ['error' => $set['uploads_error']]);
        }

        return back()->with('update_result', $steps);
    }

    /**
     * Put a backup set back.
     *
     * The dangerous button on this page, so it takes the timestamp typed
     * out, and it takes a fresh backup of the CURRENT state first — a
     * restore chosen by mistake has to be undoable too.
     */
    public function restore(Request $request, Backups $backup): RedirectResponse
    {
        $validated = $request->validate([
            'stamp' => ['required', 'string'],
            'confirm' => ['required', 'same:stamp'],
        ]);

        $set = $backup->find($validated['stamp']);

        if ($set === null) {
            return back()->with('update_error', __('admin.backup_missing'));
        }

        if (! $backup->canRestoreDatabase()) {
            return back()->with('update_error', __('admin.restore_manual', [
                'command' => $backup->restoreHint($set['database']),
            ]));
        }

        $steps = [];

        try {
            $safety = $backup->createSet();
            $steps[] = __('admin.restore_safety', ['name' => basename($safety['database'])]);
        } catch (\Throwable $e) {
            return back()->with('update_error', __('admin.restore_no_safety', ['error' => $e->getMessage()]));
        }

        Artisan::call('down', ['--retry' => 60]);

        $ok = $backup->restore($set['database']);
        $steps[] = $ok ? __('admin.restore_database_done') : __('admin.restore_database_failed');

        if ($ok && $set['uploads'] !== null) {
            $steps[] = $backup->restoreUploads($set['uploads'])
                ? __('admin.restore_uploads_done')
                : __('admin.restore_uploads_failed');
        }

        Artisan::call('up');

        return redirect('/admin/update')->with([
            'update_result' => $steps,
            'update_ok' => $ok,
        ]);
    }

    /**
     * Download a snapshot.
     *
     * A backup that only exists on the same disk as the thing it protects
     * is half a backup. Served through the authenticated admin — it is the
     * whole database, guests and all.
     */
    public function download(string $name, Backups $backup): StreamedResponse
    {
        // basename() and a strict pattern: the filename comes from a URL,
        // and nothing here should be able to read ../../.env.
        $name = basename($name);

        if (preg_match('/^doba-\d{4}-\d{2}-\d{2}-\d{6}\.(sqlite|sql|files\.tar\.gz)$/', $name) !== 1) {
            throw new NotFoundHttpException('No such backup.');
        }

        $path = $backup->directory().'/'.$name;

        // Resolved and compared against the backup directory, so no amount
        // of cleverness in the URL reaches a file outside it.
        if (! is_file($path) || realpath($path) === false
            || ! str_starts_with((string) realpath($path), (string) realpath($backup->directory()))) {
            throw new NotFoundHttpException('No such backup.');
        }

        return response()->streamDownload(
            static function () use ($path): void {
                $handle = fopen($path, 'rb');

                while (! feof($handle)) {
                    echo fread($handle, 8192);
                }

                fclose($handle);
            },
            $name,
            ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * Delete a whole set — both halves, because half a restore is not a
     * restore.
     */
    public function destroy(string $stamp, Backups $backup): RedirectResponse
    {
        $backup->forget(basename($stamp));

        return back()->with('update_result', [__('admin.backup_deleted')]);
    }
}
