<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

/**
 * What an update did, step by step.
 *
 * A transcript rather than a boolean, because the person reading it is
 * often a hotelier on a shared host who cannot see a log file and needs
 * to know exactly how far the update got.
 */
class UpdateResult
{
    public bool $ok = false;

    /** Whether the site should be reopened when the run finishes. */
    public bool $reopen = true;

    public ?string $backupPath = null;

    public ?string $error = null;

    /** Printed when an automatic restore was not safe to attempt. */
    public ?string $restoreCommand = null;

    /** @var array<int,string> */
    public array $pending = [];

    /** @var array<int,string> */
    public array $steps = [];

    public function step(string $message): void
    {
        $this->steps[] = $message;
    }

    public function fail(string $error): void
    {
        $this->ok = false;
        $this->error = $error;
        $this->step('Failed: '.$error);
    }
}
