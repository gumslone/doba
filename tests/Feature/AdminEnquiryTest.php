<?php

declare(strict_types=1);

use App\Enums\EnquiryStatus;
use App\Mail\EnquiryReply;
use App\Models\Enquiry;
use App\Models\Guest;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Mail;

/**
 * The enquiries inbox (§12): read, answer, rescue.
 */
function inboxEnquiry(array $overrides = []): Enquiry
{
    return Enquiry::create(array_merge([
        'name' => 'Anna Kowalska',
        'email' => 'anna@example.com',
        'locale' => 'de',
        'message' => "Haben Sie ein Familienzimmer?\nErste Augustwoche.",
        'check_in' => '2027-08-01',
        'check_out' => '2027-08-08',
        'status' => EnquiryStatus::New,
    ], $overrides));
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    Mail::fake();
    Setting::put('general', 'name', 'Hotel Alpenhof');
    Setting::put('contact', 'email', 'booking@alpenhof.example');
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();
    $this->staff = User::factory()->create(['name' => 'Maria']);
});

it('keeps the inbox behind the admin session', function (): void {
    $enquiry = inboxEnquiry();

    $this->get('/admin/enquiries')->assertRedirect('/admin/login');
    $this->get('/admin/enquiries/'.$enquiry->id)->assertRedirect('/admin/login');
    $this->post('/admin/enquiries/'.$enquiry->id.'/reply', ['subject' => 'x', 'body' => 'y'])->assertRedirect('/admin/login');

    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::New);
});

it('lists the inbox without spam, and spam on its own tab', function (): void {
    inboxEnquiry(['name' => 'Anna Kowalska']);
    inboxEnquiry(['name' => 'Cheap Pills', 'email' => 'bot@example.net', 'status' => EnquiryStatus::Spam]);

    $this->actingAs($this->staff)->get('/admin/enquiries')
        ->assertOk()
        ->assertSee('Anna Kowalska')
        ->assertDontSee('Cheap Pills');

    $this->actingAs($this->staff)->get('/admin/enquiries?status=spam')
        ->assertOk()
        ->assertSee('Cheap Pills')
        ->assertDontSee('Anna Kowalska');
});

it('counts the unread ones in the sidebar and clears one by opening it', function (): void {
    $enquiry = inboxEnquiry();
    inboxEnquiry(['name' => 'Second']);

    // Any admin page carries the badge — it is the sidebar, not the inbox.
    $this->actingAs($this->staff)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('bg-amber-500 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white">2<', false);

    $this->actingAs($this->staff)->get('/admin/enquiries/'.$enquiry->id)
        ->assertOk()
        ->assertSee('Haben Sie ein Familienzimmer?')
        // Straight to the dates asked about.
        ->assertSee('check_in=2027-08-01', false);

    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Read)
        ->and(Enquiry::unreadCount())->toBe(1);
});

it('answers from the panel as the hotel\'s own mail, and keeps the answer', function (): void {
    app(MailSettings::class)->confirm();
    $enquiry = inboxEnquiry();

    $this->actingAs($this->staff)
        ->post('/admin/enquiries/'.$enquiry->id.'/reply', [
            'subject' => 'Re: Ihre Anfrage an Hotel Alpenhof',
            'body' => "Liebe Frau Kowalska,\n\nja, gern.",
        ])
        ->assertRedirect('/admin/enquiries/'.$enquiry->id)
        ->assertSessionHas('saved');

    Mail::assertQueued(EnquiryReply::class, function (EnquiryReply $mail): bool {
        $rendered = $mail->render();

        return $mail->hasTo('anna@example.com')
            && $mail->hasReplyTo('booking@alpenhof.example')
            && $mail->hasSubject('Re: Ihre Anfrage an Hotel Alpenhof')
            // Line breaks survive, the hotel signs, the question is quoted.
            && str_contains($rendered, 'Liebe Frau Kowalska,<br')
            && str_contains($rendered, 'Hotel Alpenhof')
            && str_contains($rendered, 'Haben Sie ein Familienzimmer?');
    });

    $enquiry->refresh();

    expect($enquiry->status)->toBe(EnquiryStatus::Replied)
        ->and($enquiry->reply)->toBe("Liebe Frau Kowalska,\n\nja, gern.")
        ->and($enquiry->replied_by)->toBe($this->staff->id)
        ->and($enquiry->replied_at)->not->toBeNull();

    $this->actingAs($this->staff)->get('/admin/enquiries/'.$enquiry->id)
        ->assertSee('Answered by Maria')
        ->assertSee('ja, gern.');
});

it('offers the subject in the guest\'s language and never renders their markup', function (): void {
    app(MailSettings::class)->confirm();
    $enquiry = inboxEnquiry(['locale' => 'de', 'message' => '<b>bold</b> <script>alert(1)</script>']);

    $this->actingAs($this->staff)->get('/admin/enquiries/'.$enquiry->id)
        ->assertSee('value="Re: Ihre Anfrage an Hotel Alpenhof"', false);

    $mail = new EnquiryReply($enquiry, 'Re', '<i>x</i>', 'Hotel Alpenhof', null);

    expect($mail->render())->not->toContain('<script>')
        ->not->toContain('<i>x</i>');
});

it('refuses to answer while outgoing mail is unconfirmed', function (): void {
    app(MailSettings::class)->unconfirm();
    $enquiry = inboxEnquiry();

    $this->actingAs($this->staff)
        ->from('/admin/enquiries/'.$enquiry->id)
        ->post('/admin/enquiries/'.$enquiry->id.'/reply', ['subject' => 'Re', 'body' => 'Hello'])
        ->assertRedirect('/admin/enquiries/'.$enquiry->id)
        ->assertSessionHasErrors('body');

    Mail::assertNothingQueued();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::New)
        ->and($enquiry->fresh()->reply)->toBeNull();
});

it('rescues a false positive from spam, and files a real one', function (): void {
    $caught = inboxEnquiry(['status' => EnquiryStatus::Spam]);
    $real = inboxEnquiry(['name' => 'Bot', 'status' => EnquiryStatus::Read]);

    $this->actingAs($this->staff)->post('/admin/enquiries/'.$caught->id.'/status', ['status' => 'read'])->assertRedirect();
    $this->actingAs($this->staff)->post('/admin/enquiries/'.$real->id.'/status', ['status' => 'spam'])->assertRedirect();
    $this->actingAs($this->staff)->post('/admin/enquiries/'.$real->id.'/status', ['status' => 'bogus'])->assertSessionHasErrors('status');

    expect($caught->fresh()->status)->toBe(EnquiryStatus::Read)
        ->and($real->fresh()->status)->toBe(EnquiryStatus::Spam);
});

it('links a writer who has stayed before to their guest book entry', function (): void {
    $guest = Guest::findOrCreateByEmail('anna@example.com', ['first_name' => 'Anna', 'last_name' => 'Kowalska']);
    $guest->forceFill(['stays_count' => 2])->save();
    $enquiry = inboxEnquiry();

    $this->actingAs($this->staff)->get('/admin/enquiries/'.$enquiry->id)
        ->assertSee('/admin/guests/'.$guest->id, false)
        ->assertSee('2 stays');
});

it('deletes an enquiry for good', function (): void {
    $enquiry = inboxEnquiry();

    $this->actingAs($this->staff)->post('/admin/enquiries/'.$enquiry->id.'/delete')->assertRedirect('/admin/enquiries');

    expect(Enquiry::query()->count())->toBe(0);
});
