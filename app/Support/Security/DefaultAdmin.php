<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * The account every copy of the README knows the password to.
 *
 * The demo seeder creates `admin@example.com` / `password` so that a
 * developer can log in to a seeded copy. The install wizard's "fill it
 * with demo content" option ran that same seeder — and left the account
 * behind on a production site, beside the owner's real one, with no
 * screen anywhere that lists users. Anyone who had read the documentation
 * could log in to that hotel.
 */
final class DefaultAdmin
{
    public const EMAIL = 'admin@example.com';

    public const PASSWORD = 'password';

    /**
     * The account, only while its published password still opens it.
     */
    public static function find(): ?User
    {
        try {
            $user = User::query()->where('email', self::EMAIL)->first();
        } catch (Throwable) {
            return null;   // before the first migration
        }

        if ($user === null || ! Hash::check(self::PASSWORD, (string) $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Close it, where somebody else can still get in.
     *
     * Deleted when nothing refers to it, otherwise given a password no
     * one knows. Never touched when it is the only account: locking the
     * one person with a login out of their hotel is not a fix, and the
     * health page says what to do instead.
     */
    public static function close(): bool
    {
        $user = self::find();

        if ($user === null || ! User::query()->whereKeyNot($user->getKey())->exists()) {
            return false;
        }

        try {
            $user->delete();
        } catch (Throwable) {
            $user->forceFill([
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
            ])->save();
        }

        return true;
    }
}
