<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;

class StoreEnquiryRequest extends FormRequest
{
    /**
     * Seconds a human plausibly needs between seeing the form and
     * submitting it. Bots reliably post in well under one.
     */
    public const MIN_SECONDS = 3;

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:5000'],
            'check_in' => ['nullable', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            // The honeypot and the timing token are validated in isSpam(),
            // not here: a failed *validation* re-renders the form with an
            // error, which tells a bot author exactly which field tripped
            // them. Spam must look like success.
            'website' => ['nullable', 'string'],
            '_t' => ['nullable', 'string'],
        ];
    }

    /**
     * Honeypot + timing check (§14).
     *
     * `website` is invisible to humans, so any value means a bot. `_t` is
     * the encrypted render timestamp; missing, tampered or younger than
     * MIN_SECONDS also means a bot. Callers store the enquiry as spam and
     * show the normal thank-you either way.
     */
    public function isSpam(): bool
    {
        if (($this->input('website') ?? '') !== '') {
            return true;
        }

        try {
            $renderedAt = (int) Crypt::decryptString((string) $this->input('_t'));
        } catch (DecryptException) {
            return true;
        }

        return now()->timestamp - $renderedAt < self::MIN_SECONDS;
    }

    /**
     * The encrypted timestamp the form embeds at render time.
     */
    public static function timingToken(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }
}
