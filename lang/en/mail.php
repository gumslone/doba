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

    // Unfinished-booking reminder (§13)
    'recovery_subject' => 'Your room at :hotel is still free',
    'recovery_heading' => 'Still thinking about it, :name?',
    'recovery_intro' => 'You started a booking at :hotel for :date and did not finish it. The room is still available, and picking up where you left off takes a minute.',
    'recovery_button' => 'Finish my booking',
    'recovery_outro' => 'If you changed your mind, do nothing: nothing was charged and nothing is being held. We will not write again about this.',

    // Online check-in (§12)
    'pre_arrival_checkin' => 'Save time at the desk: check in online from your booking page and you only need to sign when you arrive.',
    'post_stay_book_again' => 'Next time, book with us directly at :url — as a returning guest you get :percent% off.',

    // Cancellation, to the guest; and the hotel's own copy of the news.
    'cancelled_subject' => 'Your booking :reference at :hotel is cancelled',
    'cancelled_heading' => 'Your booking is cancelled',
    'cancelled_intro' => 'This confirms that your stay at :hotel has been cancelled. You do not need to do anything else.',
    'cancelled_voucher' => ':amount has gone back onto your gift voucher and can be used again.',
    'cancelled_refund' => 'You are due a refund of :amount under the terms of your rate. We will return it the way you paid; card refunds usually take a few working days to show.',
    'cancelled_no_refund' => 'Under the terms of your rate, the :amount already paid is not refundable.',
    'cancelled_nothing_paid' => 'Nothing had been paid, so there is nothing to refund.',
    'cancelled_outro' => 'We are sorry not to see you this time, and hope to welcome you at :hotel another day.',
    'notice_booked_subject' => 'New booking :reference — :name, arriving :date',
    'notice_cancelled_subject' => 'Cancelled: :reference — :name, :date',
    'notice_booked_heading' => 'A new booking',
    'notice_cancelled_heading' => 'A booking was cancelled',
    'notice_nights' => '{1} :count night|[2,*] :count nights',
    'notice_guests' => '{1} :count guest|[2,*] :count guests',
    'notice_paid' => 'paid so far: :amount',
    'notice_requests' => 'The guest wrote:',
    'notice_voucher_restored' => ':amount went back onto the gift voucher automatically.',
    'notice_refund_owed' => 'A refund of :amount is owed. Open the booking to send it.',
    'notice_no_refund' => 'Nothing is owed back: the :amount paid is not refundable under the rate that was booked.',
    'notice_open' => 'Open the booking',
];
