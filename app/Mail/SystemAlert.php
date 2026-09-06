<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Hotel\HotelSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Something on your site needs a look" (§15).
 *
 * Staff-facing and English-only, like the admin: it goes to whoever
 * runs the hotel's website, not to a guest.
 */
class SystemAlert extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,string>  $lines
     */
    public function __construct(public string $subjectLine, public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.app(HotelSettings::class)->name.'] '.$this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.system-alert', with: [
            'subjectLine' => $this->subjectLine,
            'lines' => $this->lines,
            'adminUrl' => url('/admin/update'),
        ]);
    }
}
