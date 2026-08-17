<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Maintenance\DatabaseBackup;
use App\Support\Maintenance\Updater;
use App\Support\Version;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public function index(Updater $updater, DatabaseBackup $backup): View
    {
        return view('admin.update.index', [
            'version' => Version::current(),
            'pending' => $updater->pendingMigrations(),
            'backupSupported' => $backup->isSupported(),
            'backupReason' => $backup->unsupportedReason(),
            'backups' => $backup->all(),
        ]);
    }

    public function run(Request $request, Updater $updater, DatabaseBackup $backup): RedirectResponse
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
    public function backup(DatabaseBackup $backup): RedirectResponse
    {
        if (! $backup->isSupported()) {
            return back()->with('update_error', __('admin.update_no_backup', [
                'reason' => (string) $backup->unsupportedReason(),
            ]));
        }

        $path = $backup->create();
        $backup->prune();

        return back()->with('update_result', [
            __('admin.backup_taken', ['name' => basename($path)]),
        ]);
    }

    /**
     * Download a snapshot.
     *
     * A backup that only exists on the same disk as the thing it protects
     * is half a backup. Served through the authenticated admin — it is the
     * whole database, guests and all.
     */
    public function download(string $name, DatabaseBackup $backup): StreamedResponse
    {
        // basename() and a strict pattern: the filename comes from a URL,
        // and nothing here should be able to read ../../.env.
        $name = basename($name);

        if (preg_match('/^doba-\d{4}-\d{2}-\d{2}-\d{6}\.(sqlite|sql)$/', $name) !== 1) {
            throw new NotFoundHttpException('No such backup.');
        }

        $match = collect($backup->all())->firstWhere('path', storage_path('app/backups/'.$name));

        if ($match === null) {
            throw new NotFoundHttpException('No such backup.');
        }

        return response()->streamDownload(
            static function () use ($match): void {
                $handle = fopen($match['path'], 'rb');

                while (! feof($handle)) {
                    echo fread($handle, 8192);
                }

                fclose($handle);
            },
            $name,
            ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
        );
    }

    public function destroy(string $name, DatabaseBackup $backup): RedirectResponse
    {
        $name = basename($name);
        $path = storage_path('app/backups/'.$name);

        if (preg_match('/^doba-\d{4}-\d{2}-\d{2}-\d{6}\.(sqlite|sql)$/', $name) === 1 && is_file($path)) {
            Storage::disk('local')->delete('backups/'.$name);
        }

        return back()->with('update_result', [__('admin.backup_deleted')]);
    }
}
