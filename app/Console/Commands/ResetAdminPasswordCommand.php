<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The way back in (§14).
 *
 * There is no "forgot password" on the login page on purpose: for a
 * single-owner hotel, a reset link is one more thing a phished inbox
 * can use. Whoever can run a command on the server is the owner, and
 * this is their reset. `--clear-2fa` is for the phone that fell in the
 * lake with the recovery codes still in its notes app.
 */
class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'doba:admin:reset-password
                            {email : The admin account}
                            {--password= : The new password (generated if omitted)}
                            {--clear-2fa : Also switch off two-factor sign-in for this account}';

    protected $description = 'Set a new password for an admin account from the shell';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No admin account with that email.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));

        if (strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $changes = ['password' => Hash::make($password)];

        if ($this->option('clear-2fa')) {
            $changes += ['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_recovery_codes' => null];
        }

        $user->forceFill($changes)->save();

        $this->info('Password set for '.$user->email.'.');

        if (! $this->option('password')) {
            $this->line('New password: '.$password);
            $this->line('Change it after signing in — Admin → Your account.');
        }

        if ($this->option('clear-2fa')) {
            $this->line('Two-factor sign-in is off for this account.');
        }

        return self::SUCCESS;
    }
}
