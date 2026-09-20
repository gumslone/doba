<?php

declare(strict_types=1);

/*
 * Gift vouchers (§8), guest-facing: the order page, the two mails, the
 * printed voucher, and redemption on the booking page.
 */

return [
    'one' => 'Gift voucher',
    'title' => 'Gift vouchers',
    'meta_description' => 'Give a stay at :hotel: a gift voucher in any amount, valid for years, redeemable online against any booking.',
    'lede' => 'A stay is a better present than another thing. Choose an amount; the voucher can be spent on any room, in one go or across several stays.',
    'amount' => 'Amount',
    'amount_other' => 'Another amount',
    'recipient_name' => 'Who is it for?',
    'recipient_hint' => 'The name printed on the voucher. Leave empty to fill it in by hand.',
    'message' => 'A few words on the voucher',
    'message_hint' => 'Optional — printed exactly as you write it.',
    'buyer_name' => 'Your name',
    'buyer_email' => 'Your email',
    'order' => 'Order the voucher',
    'how_title' => 'How it works',
    'how_1' => 'You order here and we send you our payment details by email.',
    'how_2' => 'As soon as your payment arrives, the voucher comes to you as a PDF to print or forward.',
    'how_3' => 'It is redeemed with its code when booking on this website, or at the desk.',
    'ordered_title' => 'Thank you — your order is with us',
    'ordered_body' => 'We have sent our payment details to :email. The voucher becomes valid, and is sent to you, as soon as the payment has arrived.',
    'code' => 'Voucher code',
    'value' => 'Value',
    'valid_until' => 'Valid until',
    'for' => 'For',
    'from' => 'From',
    'redeem_title' => 'Pay with a gift voucher',
    'redeem_hint' => 'Type the code printed on your voucher. It is taken off what is still to pay; anything left stays on the voucher.',
    'redeem_label' => 'Voucher code',
    'redeem_button' => 'Redeem',
    'redeemed' => ':amount was taken from your voucher.',
    'remaining' => 'Still on the voucher: :amount.',
    'error_unknown' => 'We do not know this voucher code. Please check it against the voucher.',
    'error_unpaid' => 'This voucher has not been paid for yet, so it cannot be used.',
    'error_void' => 'This voucher has been cancelled.',
    'error_expired' => 'This voucher has expired.',
    'error_empty' => 'This voucher has already been spent.',
    'error_currency' => 'This voucher is in a different currency than the booking.',
    'error_nothing_due' => 'There is nothing left to pay on this booking.',
    'error_booking_closed' => 'This booking can no longer be paid.',
    'error_not_pending' => 'This voucher is not waiting for payment.',
    'error_amount' => 'Please choose an amount between :min and :max.',
    'mail_ordered_subject' => 'Your gift voucher order at :hotel',
    'mail_ordered_intro' => 'Thank you, :name. We have reserved voucher :code over :amount for you.',
    'mail_ordered_instructions' => 'To pay',
    'mail_ordered_outro' => 'The voucher is sent to you as soon as your payment has arrived. Please quote the voucher code with your payment.',
    'mail_issued_subject' => 'Your gift voucher for :hotel',
    'mail_issued_intro' => 'Thank you, :name — your payment has arrived. The voucher over :amount is attached as a PDF, ready to print or forward.',
    'mail_issued_how' => 'It is redeemed with the code :code when booking on our website, or at the desk. Valid until :date.',
    'pdf_redeem' => 'Redeem when booking at :url or at the desk.',
];
