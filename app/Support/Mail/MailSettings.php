<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The hotel's outgoing mail, configured from the admin rather than from
 * `.env` (§13, §16).
 *
 * It lives in settings for the same reason everything else does: the
 * person who needs to change it has a browser, not a shell. And it has
 * one property nothing else here has — **it is not considered working
 * until a human confirms they received a test message.**
 *
 * That confirmation is the whole point. Mail is the one subsystem that
 * fails completely silently: SMTP accepts the message, the queue reports
 * success, every page looks right, and the guest simply never gets their
 * confirmation. Nobody finds out until somebody arrives at the desk
 * holding nothing. A green tick that means "the server accepted it" is
 * worse than no tick at all, because it stops anyone looking.
 */
class MailSettings
{
    public const GROUP = 'mail';

    /** Transports a hotelier can actually choose between. */
    public const TRANSPORTS = ['smtp', 'log'];

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $rows = Setting::query()->where('group', self::GROUP)->pluck('value', 'key')->all();

        return [
            'transport' => $rows['transport'] ?? 'log',
            'host' => $rows['host'] ?? null,
            'port' => $rows['port'] ?? 587,
            'username' => $rows['username'] ?? null,
            'encryption' => $rows['encryption'] ?? 'tls',
            'from_address' => $rows['from_address'] ?? null,
            'from_name' => $rows['from_name'] ?? null,
            'confirmed_at' => $rows['confirmed_at'] ?? null,
            'last_tested_at' => $rows['last_tested_at'] ?? null,
        ];
    }

    /**
     * The SMTP password.
     *
     * Encrypted at rest: the settings table is in every backup a hotelier
     * downloads and emails to themselves, and an SMTP credential in there
     * in plain text is a credential in their inbox.
     */
    public function password(): ?string
    {
        $stored = Setting::query()->where('group', self::GROUP)->where('key', 'password')->value('value');

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            // A backup restored onto an install with a different APP_KEY.
            // Better to report no password than to hand the transport
            // ciphertext and let it fail as "authentication rejected".
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (['transport', 'host', 'port', 'username', 'encryption', 'from_address', 'from_name'] as $key) {
            if (array_key_exists($key, $values)) {
                Setting::put(self::GROUP, $key, $values[$key]);
            }
        }

        // Blank means "leave it alone", so re-saving the form without
        // retyping the password does not wipe it.
        if (($values['password'] ?? null) !== null && $values['password'] !== '') {
            Setting::put(self::GROUP, 'password', Crypt::encryptString((string) $values['password']));
        }

        // Any change invalidates the confirmation. A hotelier who edits
        // the host and leaves the old green tick in place has a tick that
        // describes a configuration that no longer exists.
        $this->unconfirm();
    }

    /**
     * Push the stored settings into the live mail config.
     */
    public function apply(): void
    {
        $settings = $this->all();

        if ($settings['transport'] === 'log') {
            config(['mail.default' => 'log']);

            return;
        }

        if (($settings['host'] ?? null) === null) {
            return;   // half-configured is not configured; leave the default
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => (int) $settings['port'],
            'mail.mailers.smtp.username' => $settings['username'],
            'mail.mailers.smtp.password' => $this->password(),
            'mail.mailers.smtp.encryption' => $settings['encryption'] === 'none' ? null : $settings['encryption'],
        ]);

        if (($settings['from_address'] ?? null) !== null) {
            config([
                'mail.from.address' => $settings['from_address'],
                'mail.from.name' => $settings['from_name'] ?: config('app.name'),
            ]);
        }
    }

    /**
     * Has a human confirmed a test message actually arrived?
     */
    public function isConfirmed(): bool
    {
        return ($this->all()['confirmed_at'] ?? null) !== null;
    }

    public function confirm(): void
    {
        Setting::put(self::GROUP, 'confirmed_at', CarbonImmutable::now()->toIso8601String());
    }

    public function unconfirm(): void
    {
        Setting::query()->where('group', self::GROUP)->where('key', 'confirmed_at')->delete();
    }

    public function recordTest(): void
    {
        Setting::put(self::GROUP, 'last_tested_at', CarbonImmutable::now()->toIso8601String());
    }

    /**
     * The DNS records this hotel needs for its mail to be delivered.
     *
     * Printed here because the alternative is a hotelier discovering,
     * months later, that every confirmation has been going to spam — and
     * the person who can add a TXT record is usually the same person
     * reading this page.
     *
     * @return array<int,array{type:string,host:string,value:string,note:string}>
     */
    public function dnsRecords(): array
    {
        $domain = $this->domain();

        if ($domain === null) {
            return [];
        }

        return [
            [
                'type' => 'TXT',
                'host' => $domain,
                'value' => 'v=spf1 include:'.($this->all()['host'] ?? 'your-provider.example').' ~all',
                'note' => __('mail.spf_note'),
            ],
            [
                'type' => 'TXT',
                'host' => '_dmarc.'.$domain,
                // Quarantine rather than reject to start with: a hotel
                // that publishes p=reject on day one and has SPF slightly
                // wrong stops delivering its own confirmations.
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain,
                'note' => __('mail.dmarc_note'),
            ],
            [
                'type' => 'TXT',
                'host' => 'selector._domainkey.'.$domain,
                'value' => __('mail.dkim_value'),
                'note' => __('mail.dkim_note'),
            ],
        ];
    }

    public function domain(): ?string
    {
        $from = $this->all()['from_address'] ?? null;

        if (! is_string($from) || ! str_contains($from, '@')) {
            return null;
        }

        return mb_strtolower(explode('@', $from)[1]);
    }
}
