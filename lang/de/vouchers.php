<?php

declare(strict_types=1);

/*
 * Gift vouchers (§8), guest-facing: the order page, the two mails, the
 * printed voucher, and redemption on the booking page.
 */

return [
    'one' => 'Gutschein',
    'title' => 'Gutscheine',
    'meta_description' => 'Verschenken Sie einen Aufenthalt im :hotel: ein Gutschein über einen beliebigen Betrag, jahrelang gültig, online bei jeder Buchung einlösbar.',
    'lede' => 'Ein Aufenthalt ist ein besseres Geschenk als noch ein Gegenstand. Wählen Sie einen Betrag; der Gutschein gilt für jedes Zimmer, auf einmal oder über mehrere Aufenthalte.',
    'amount' => 'Betrag',
    'amount_other' => 'Anderer Betrag',
    'recipient_name' => 'Für wen ist er?',
    'recipient_hint' => 'Der Name, der auf dem Gutschein steht. Leer lassen, um ihn von Hand einzutragen.',
    'message' => 'Ein paar Worte auf dem Gutschein',
    'message_hint' => 'Optional — wird genau so gedruckt, wie Sie es schreiben.',
    'buyer_name' => 'Ihr Name',
    'buyer_email' => 'Ihre E-Mail-Adresse',
    'order' => 'Gutschein bestellen',
    'how_title' => 'So funktioniert es',
    'how_1' => 'Sie bestellen hier, und wir senden Ihnen unsere Zahlungsdaten per E-Mail.',
    'how_2' => 'Sobald Ihre Zahlung eingegangen ist, erhalten Sie den Gutschein als PDF zum Ausdrucken oder Weiterleiten.',
    'how_3' => 'Eingelöst wird er mit seinem Code bei der Buchung auf dieser Website oder an der Rezeption.',
    'ordered_title' => 'Vielen Dank — Ihre Bestellung ist bei uns',
    'ordered_body' => 'Wir haben unsere Zahlungsdaten an :email gesendet. Der Gutschein wird gültig und Ihnen zugesandt, sobald die Zahlung eingegangen ist.',
    'code' => 'Gutscheincode',
    'value' => 'Wert',
    'valid_until' => 'Gültig bis',
    'for' => 'Für',
    'from' => 'Von',
    'redeem_title' => 'Mit Gutschein bezahlen',
    'redeem_hint' => 'Geben Sie den Code von Ihrem Gutschein ein. Er wird vom offenen Betrag abgezogen; ein Rest bleibt auf dem Gutschein.',
    'redeem_label' => 'Gutscheincode',
    'redeem_button' => 'Einlösen',
    'redeemed' => ':amount wurden von Ihrem Gutschein abgezogen.',
    'remaining' => 'Noch auf dem Gutschein: :amount.',
    'error_unknown' => 'Diesen Gutscheincode kennen wir nicht. Bitte vergleichen Sie ihn mit dem Gutschein.',
    'error_unpaid' => 'Dieser Gutschein wurde noch nicht bezahlt und kann daher nicht verwendet werden.',
    'error_void' => 'Dieser Gutschein wurde storniert.',
    'error_expired' => 'Dieser Gutschein ist abgelaufen.',
    'error_empty' => 'Dieser Gutschein ist bereits aufgebraucht.',
    'error_currency' => 'Dieser Gutschein lautet auf eine andere Währung als die Buchung.',
    'error_nothing_due' => 'Bei dieser Buchung ist nichts mehr offen.',
    'error_booking_closed' => 'Diese Buchung kann nicht mehr bezahlt werden.',
    'error_not_pending' => 'Dieser Gutschein wartet nicht auf Zahlung.',
    'error_amount' => 'Bitte wählen Sie einen Betrag zwischen :min und :max.',
    'mail_ordered_subject' => 'Ihre Gutscheinbestellung im :hotel',
    'mail_ordered_intro' => 'Vielen Dank, :name. Wir haben den Gutschein :code über :amount für Sie reserviert.',
    'mail_ordered_instructions' => 'Zahlung',
    'mail_ordered_outro' => 'Der Gutschein wird Ihnen zugesandt, sobald Ihre Zahlung eingegangen ist. Bitte geben Sie bei der Zahlung den Gutscheincode an.',
    'mail_issued_subject' => 'Ihr Gutschein für das :hotel',
    'mail_issued_intro' => 'Vielen Dank, :name — Ihre Zahlung ist eingegangen. Der Gutschein über :amount liegt als PDF bei, zum Ausdrucken oder Weiterleiten.',
    'mail_issued_how' => 'Eingelöst wird er mit dem Code :code bei der Buchung auf unserer Website oder an der Rezeption. Gültig bis :date.',
    'pdf_redeem' => 'Einlösbar bei der Buchung auf :url oder an der Rezeption.',
];
