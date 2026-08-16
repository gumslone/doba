<?php

declare(strict_types=1);

use App\Enums\EnquiryStatus;
use App\Http\Requests\StoreEnquiryRequest;
use App\Mail\EnquiryReceived;
use App\Models\Enquiry;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

function enquiryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Anna Kowalska',
        'email' => 'anna@example.com',
        'message' => 'Do you have a family room for the first week of August?',
        'website' => '',
        // A token old enough to pass the timing check.
        '_t' => Crypt::encryptString((string) now()->subSeconds(30)->timestamp),
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    Mail::fake();
    RateLimiter::clear('contact:127.0.0.1');

    Setting::put('contact', 'email', 'booking@alpenhof.example');
    HotelSettings::flush();
});

it('renders the form on its translated path in each locale', function (): void {
    $this->get('/en/contact')->assertOk()->assertSee('name="message"', false);
    $this->get('/de/kontakt')->assertOk()->assertSee('Nachricht senden');
});

it('stores a valid enquiry and queues the mail to the hotel inbox', function (): void {
    $this->from('/de/kontakt')
        ->post('/de/kontakt', enquiryPayload())
        ->assertRedirect()
        ->assertSessionHas('enquiry_sent');

    $enquiry = Enquiry::sole();

    expect($enquiry->status)->toBe(EnquiryStatus::New)
        ->and($enquiry->locale)->toBe('de')
        ->and($enquiry->email)->toBe('anna@example.com');

    Mail::assertQueued(EnquiryReceived::class, fn (EnquiryReceived $mail): bool => $mail->hasTo('booking@alpenhof.example')
        && $mail->enquiry->is($enquiry));
});

it('flags a filled honeypot as spam while showing the normal thank-you', function (): void {
    $this->post('/en/contact', enquiryPayload(['website' => 'https://spam.example']))
        ->assertRedirect()
        ->assertSessionHas('enquiry_sent');

    expect(Enquiry::sole()->status)->toBe(EnquiryStatus::Spam);

    Mail::assertNothingQueued();
});

it('flags a submission faster than a human as spam', function (): void {
    $this->post('/en/contact', enquiryPayload([
        '_t' => StoreEnquiryRequest::timingToken(), // rendered "just now"
    ]))->assertRedirect();

    expect(Enquiry::sole()->status)->toBe(EnquiryStatus::Spam);
});

it('flags a missing or tampered timing token as spam', function (): void {
    $this->post('/en/contact', enquiryPayload(['_t' => 'forged']))->assertRedirect();

    expect(Enquiry::sole()->status)->toBe(EnquiryStatus::Spam);
});

it('validates the stay dates as a range', function (): void {
    $this->from('/en/contact')
        ->post('/en/contact', enquiryPayload([
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-08',
        ]))
        ->assertRedirect('/en/contact')
        ->assertSessionHasErrors('check_out');

    expect(Enquiry::count())->toBe(0);
});

it('rate limits the form per IP', function (): void {
    foreach (range(1, 5) as $i) {
        $this->post('/en/contact', enquiryPayload())->assertRedirect();
    }

    $this->post('/en/contact', enquiryPayload())->assertStatus(429);

    expect(Enquiry::count())->toBe(5);
});

it('lists the contact page in the sitemap with alternates', function (): void {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain(seoHost().'/en/contact')
        ->and($xml)->toContain(seoHost().'/de/kontakt');
});
