<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'room_type' => ['required', 'integer', 'exists:room_types,id'],
            'rate_plan' => ['nullable', 'integer', 'exists:rate_plans,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],

            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'size:2'],
            'guest_notes' => ['nullable', 'string', 'max:2000'],

            'extras' => ['nullable', 'array'],
            'extras.*' => ['nullable', 'integer', 'min:0', 'max:99'],

            // Consent to the cancellation policy is a legal precondition of
            // taking the booking, so it is required rather than merely
            // recorded — and marketing consent is separate, per GDPR (§14).
            'terms' => ['accepted'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => __('booking.error_terms'),
        ];
    }
}
