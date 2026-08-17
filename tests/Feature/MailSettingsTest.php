<?php

declare(strict_types=1);

use App\Mail\TestMessage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->mail = app(MailSettings::class);
    $this->admin = User::factory()->create();

    $this->configure = fn (array $overrides = []) => $this->actingAs($this->admin)->put('/admin/mail', array_merge([
        'transport' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'hotel',
        'password' => 'sekrit',
        'encryption' => 'tls',
        'from_address' => 'stay@alpenhof.example',
        'from_name' => 'Hotel Alpenhof',
    ], $overrides));
});

it('treats mail as broken until a human says a message arrived', function (): void {
    ($this->configure)()->assertRedirect('/admin/mail');

    // Saved is not working. SMTP accepting a message, a queue reporting
    // success and every page looking right are all compatible with the
    // guest receiving nothing.
    expect($this->mail->isConfirmed())->toBeFalse();

    $this->actingAs($this->admin)->get('/admin/mail')
        ->assertOk()
        ->assertSee(__('admin.mail_unconfirmed'));
});

it('warns on every admin page until mail is confirmed', function (): void {
    ($this->configure)();

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee(__('admin.mail_unconfirmed'));

    $this->mail->confirm();

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertDontSee(__('admin.mail_unconfirmed'));
});

it('confirms only on a code that came out of the message', function (): void {
    Mail::fake();
    ($this->configure)();

    $this->actingAs($this->admin)
        ->post('/admin/mail/test', ['to' => 'owner@alpenhof.example'])
        ->assertRedirect('/admin/mail');

    $code = session('mail_test_code');

    expect($code)->toBeString()->toHaveLength(6);

    Mail::assertSent(TestMessage::class, fn (TestMessage $m): bool => $m->code === $code);

    // A wrong code is refused. A checkbox would be a thing people tick to
    // make a warning go away; a code can only come from a message that
    // actually arrived.
    $this->actingAs($this->admin)
        ->post('/admin/mail/confirm', ['expected' => $code, 'code' => 'WRONG1'])
        ->assertSessionHas('mail_error');

    expect($this->mail->isConfirmed())->toBeFalse();

    $this->actingAs($this->admin)
        ->post('/admin/mail/confirm', ['expected' => $code, 'code' => strtolower($code)])
        ->assertRedirect('/admin/mail');

    expect($this->mail->isConfirmed())->toBeTrue();
});

it('un-confirms the moment a setting changes', function (): void {
    ($this->configure)();
    $this->mail->confirm();

    expect($this->mail->isConfirmed())->toBeTrue();

    ($this->configure)(['host' => 'smtp.elsewhere.example']);

    // A green tick describing a configuration that no longer exists is
    // worse than no tick at all.
    expect($this->mail->isConfirmed())->toBeFalse();
});

it('encrypts the SMTP password, because it rides in every backup', function (): void {
    ($this->configure)();

    $stored = Setting::query()->where('group', 'mail')->where('key', 'password')->value('value');

    expect($stored)->not->toBe('sekrit')
        ->and(Crypt::decryptString($stored))->toBe('sekrit')
        ->and($this->mail->password())->toBe('sekrit');
});

it('keeps the stored password when the form is saved without one', function (): void {
    ($this->configure)();

    ($this->configure)(['password' => '', 'from_name' => 'Alpenhof']);

    // Re-saving the form to change the sender name must not silently
    // empty the credential and break every future confirmation.
    expect($this->mail->password())->toBe('sekrit');
});

it('pushes the stored settings into the live config before sending', function (): void {
    ($this->configure)();

    config(['mail.default' => 'array', 'mail.mailers.smtp.host' => 'stale.example']);

    $this->mail->apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('sekrit')
        ->and(config('mail.from.address'))->toBe('stay@alpenhof.example');
});

it('does not half-apply an incomplete configuration', function (): void {
    Setting::put('mail', 'transport', 'smtp');   // and nothing else

    config(['mail.default' => 'log']);

    // Half-configured is not configured: switching the transport to SMTP
    // with no host would turn working log output into a connection error.
    $this->mail->apply();

    expect(config('mail.default'))->toBe('log');
});

it('reports a send failure instead of claiming success', function (): void {
    ($this->configure)(['host' => 'no-such-host.invalid', 'port' => 1]);

    $this->actingAs($this->admin)
        ->post('/admin/mail/test', ['to' => 'owner@alpenhof.example'])
        ->assertSessionHas('mail_error');

    expect($this->mail->isConfirmed())->toBeFalse();
});

it('prints the DNS records a hotel needs to be delivered at all', function (): void {
    ($this->configure)();

    $records = $this->mail->dnsRecords();

    expect($records)->toHaveCount(3)
        ->and($this->mail->domain())->toBe('alpenhof.example');

    $types = collect($records)->pluck('host')->implode(' ');

    expect($types)->toContain('alpenhof.example')
        ->toContain('_dmarc.alpenhof.example')
        ->toContain('_domainkey.alpenhof.example');

    // Quarantine, not reject: a hotel that publishes p=reject on day one
    // with SPF slightly wrong stops delivering its own confirmations.
    expect(collect($records)->firstWhere('host', '_dmarc.alpenhof.example')['value'])
        ->toContain('p=quarantine');
});

it('keeps mail settings behind the admin session', function (): void {
    $this->get('/admin/mail')->assertRedirect('/admin/login');
    $this->put('/admin/mail', [])->assertRedirect('/admin/login');
    $this->post('/admin/mail/test', ['to' => 'a@b.example'])->assertRedirect('/admin/login');
    $this->post('/admin/mail/confirm', ['code' => 'X', 'expected' => 'X'])->assertRedirect('/admin/login');
});

it('renders the test message, code and all', function (): void {
    // Rendering it, not just dispatching it. The first version passed the
    // hotel name as `hotel` — the same name a global view composer shares
    // the HotelSettings OBJECT under — so the template threw and the one
    // message whose entire job is to prove mail works never sent.
    $rendered = (new TestMessage('Hotel Alpenhof', 'AB12CD'))->render();

    expect($rendered)->toContain('AB12CD')
        ->toContain('Hotel Alpenhof')
        ->toContain(__('mail.test_instruction'));
});
