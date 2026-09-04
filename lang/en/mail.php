<?php

declare(strict_types=1);

return [
    'test_subject' => 'Test message from :hotel',
    'test_heading' => 'Your mail is working',
    'test_body' => 'This is a test message from :hotel. If you are reading it, your hotel can send confirmations to guests.',
    'test_instruction' => 'Type this code back into Admin → Mail to confirm it arrived.',
    'test_signoff' => 'You can delete this message.',

    'spf_note' => 'Tells other mail servers that this host may send mail as your domain. Without it your confirmations are likely to be filed as spam.',
    'dmarc_note' => 'Tells other servers what to do with mail that fails the checks. Starts at "quarantine" rather than "reject" on purpose — a hotel that rejects on day one and has SPF slightly wrong stops delivering its own confirmations.',
    'dkim_note' => 'Your mail provider gives you this value and the selector to replace in the host. Signing proves the message was not altered in transit.',
    'dkim_value' => '(the value your mail provider gives you)',

    'booking_subject' => 'Your booking :reference is confirmed',
    'booking_intro' => 'Thank you, :name — we are looking forward to your visit.',

    'pre_arrival_subject' => 'Your stay at :hotel is coming up',
    'pre_arrival_heading' => 'See you soon, :name',
    'pre_arrival_intro' => 'We are looking forward to welcoming you at :hotel on :date.',
    'check_in_from' => 'Check-in from',
    'address' => 'Address',
    'phone' => 'Phone',
    'pre_arrival_balance' => 'There is :amount left to pay on your stay. You can settle it online now, so your arrival starts with a key instead of a card terminal.',
    'pre_arrival_time_ask' => 'If you already know when you will arrive, let us know on your booking page — your room will be ready.',
    'pre_arrival_outro' => 'Safe travels — see you soon.',

    'post_stay_subject' => 'Thank you for staying at :hotel',
    'post_stay_heading' => 'Thank you, :name',
    'post_stay_intro' => 'We hope you had a wonderful stay at :hotel. It was a pleasure to have you.',
    'post_stay_invoice' => 'Your invoice is on your booking page, in case the trip goes on an expense report.',
    'post_stay_outro' => 'We would love to welcome you again.',

    'post_stay_review_ask' => 'If you have a minute, a short review on your booking page helps other travellers — and us — more than you would think. Only real guests can write one.',
];
