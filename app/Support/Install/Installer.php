<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether this copy of Doba has been installed, and how far through (§16).
 *
 * Two independent markers, because each fails in its own way: a deploy
 * that rsyncs `storage/` deletes the lock file, and a database restored
 * from a backup carries a row describing a filesystem nobody has set up.
 * Installed means BOTH. When they disagree the wizard opens in repair
 * mode and says what is missing, rather than offering a fresh install
 * that would migrate over a hotel's live reservations.
 */
class Installer
{
    /** In order. `finish` is written last and is what completes an install. */
    public const STEPS = ['language', 'requirements', 'database', 'hotel', 'owner', 'rooms', 'finish'];

    /** Where progress lives until there is a database to keep it in. */
    public const SESSION_KEY = 'install_steps';

    public function lockPath(): string
    {
        return (string) config('doba.install.lock_path', storage_path('installed.lock'));
    }

    public function tokenPath(): string
    {
        return (string) config('doba.install.token_path', storage_path('install-token.txt'));
    }

    public function hasLock(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * Is there a completed installation row?
     *
     * Wrapped, and deliberately so: on a fresh clone there is no database
     * at all, and the middleware that calls this runs on every request
     * including the one that is about to create it.
     */
    public function hasRecord(): bool
    {
        try {
            return Schema::hasTable('installations')
                && DB::table('installations')->whereNotNull('installed_at')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function isInstalled(): bool
    {
        return $this->hasLock() && $this->hasRecord();
    }

    /**
     * Exactly one marker present: something is wrong that a fresh install
     * would make worse.
     */
    public function needsRepair(): bool
    {
        return $this->hasLock() !== $this->hasRecord();
    }

    /**
     * How far the install has got.
     *
     * Merged from two places on purpose. The database is the durable
     * record — but the first two steps run BEFORE there is a database to
     * write to, and a wizard that cannot remember choosing a language is
     * a wizard that can never reach the step that creates the database.
     * So the session carries progress until the database can, and both
     * are read from here so there is one answer to "how far are we".
     *
     * @return array<int,string>
     */
    public function completedSteps(): array
    {
        return array_values(array_unique([...$this->sessionSteps(), ...$this->recordedSteps()]));
    }

    /**
     * @return array<int,string>
     */
    protected function recordedSteps(): array
    {
        try {
            $row = DB::table('installations')->latest('id')->first();
        } catch (Throwable) {
            return [];
        }

        if ($row === null) {
            return [];
        }

        $steps = json_decode((string) ($row->steps_completed ?? '[]'), true);

        return is_array($steps) ? array_values(array_filter($steps, 'is_string')) : [];
    }

    /**
     * @return array<int,string>
     */
    protected function sessionSteps(): array
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            // The console has no session, and neither does the request
            // that runs before middleware has started one.
            return [];
        }

        $steps = app('session')->get(self::SESSION_KEY, []);

        return is_array($steps) ? array_values(array_filter($steps, 'is_string')) : [];
    }

    /**
     * The step the wizard should show: the first one not yet done.
     */
    public function currentStep(): string
    {
        $done = $this->completedSteps();

        foreach (self::STEPS as $step) {
            if (! in_array($step, $done, true)) {
                return $step;
            }
        }

        return 'finish';
    }

    public function isStepAvailable(string $step): bool
    {
        $index = array_search($step, self::STEPS, true);
        $current = array_search($this->currentStep(), self::STEPS, true);

        // Going back to a finished step is fine; skipping ahead is not,
        // because step 5 writes an owner into a database step 3 creates.
        return $index !== false && $current !== false && $index <= $current;
    }

    public function markComplete(string $step, ?string $locale = null): void
    {
        $done = array_values(array_unique([...$this->completedSteps(), $step]));

        // Session first, and unconditionally: it is the only record that
        // exists during the two steps before the database does.
        if (app()->bound('session') && app('session')->isStarted()) {
            app('session')->put(self::SESSION_KEY, $done);
        }

        $row = DB::table('installations')->latest('id')->first();

        $payload = [
            'steps_completed' => json_encode($done),
            'updated_at' => CarbonImmutable::now(),
        ];

        if ($locale !== null) {
            $payload['locale'] = $locale;
        }

        if ($row === null) {
            DB::table('installations')->insert($payload + [
                'created_at' => CarbonImmutable::now(),
                'locale' => $locale ?? 'en',
            ]);

            return;
        }

        DB::table('installations')->where('id', $row->id)->update($payload);
    }

    /**
     * Complete the install: stamp the row, drop the lock, burn the token.
     */
    public function finish(): void
    {
        $this->markComplete('finish');

        DB::table('installations')->latest('id')->limit(1)->update([
            'installed_at' => CarbonImmutable::now(),
            'version' => Version::current(),
        ]);

        file_put_contents($this->lockPath(), (string) CarbonImmutable::now());
        // Read-only: the lock is a fact, not a setting.
        @chmod($this->lockPath(), 0400);

        // The token authorised the install and has no further use. Leaving
        // it on disk leaves a credential lying about.
        if (is_file($this->tokenPath())) {
            @unlink($this->tokenPath());
        }
    }

    /**
     * The token whoever is installing must read off the server.
     *
     * The wizard runs before there is any authentication at all, so this
     * is what stands in for it: anyone who can read a file on the server
     * is exactly the person entitled to install onto it. Created on first
     * load so a fresh clone is never briefly open to whoever finds it.
     */
    public function token(): string
    {
        if (! is_file($this->tokenPath()) || trim((string) file_get_contents($this->tokenPath())) === '') {
            file_put_contents($this->tokenPath(), Str::random(32));
            @chmod($this->tokenPath(), 0600);
        }

        return trim((string) file_get_contents($this->tokenPath()));
    }

    public function tokenMatches(?string $candidate): bool
    {
        return $candidate !== null && hash_equals($this->token(), $candidate);
    }
}
