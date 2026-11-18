<?php

declare(strict_types=1);

return [
    'booking_subject' => 'Ihre Buchung :reference ist bestätigt',
    'booking_intro' => 'Vielen Dank, :name — wir freuen uns auf Ihren Besuch.',

    'pre_arrival_subject' => 'Ihr Aufenthalt im :hotel steht bevor',
    'pre_arrival_heading' => 'Bis bald, :name',
    'pre_arrival_intro' => 'Wir freuen uns, Sie am :date im :hotel begrüßen zu dürfen.',
    'check_in_from' => 'Check-in ab',
    'address' => 'Adresse',
    'phone' => 'Telefon',
    'pre_arrival_balance' => 'Für Ihren Aufenthalt sind noch :amount offen. Sie können den Betrag jetzt online begleichen — dann beginnt Ihre Anreise mit dem Zimmerschlüssel statt mit dem Kartenterminal.',
    'pre_arrival_time_ask' => 'Wenn Sie Ihre Ankunftszeit schon kennen, teilen Sie sie uns auf Ihrer Buchungsseite mit — Ihr Zimmer ist dann bereit.',
    'pre_arrival_outro' => 'Gute Anreise — bis bald.',

    'post_stay_subject' => 'Danke für Ihren Aufenthalt im :hotel',
    'post_stay_heading' => 'Vielen Dank, :name',
    'post_stay_intro' => 'Wir hoffen, Sie hatten einen wunderbaren Aufenthalt im :hotel. Es war uns eine Freude.',
    'post_stay_invoice' => 'Ihre Rechnung finden Sie auf Ihrer Buchungsseite — falls die Reise in eine Abrechnung geht.',
    'post_stay_outro' => 'Wir würden uns freuen, Sie wieder begrüßen zu dürfen.',

    'post_stay_review_ask' => 'Wenn Sie eine Minute haben: Eine kurze Bewertung auf Ihrer Buchungsseite hilft anderen Reisenden — und uns — mehr, als man denkt. Nur echte Gäste können eine schreiben.',

    // Unfinished-booking reminder (§13)
    'recovery_subject' => 'Ihr Zimmer im :hotel ist noch frei',
    'recovery_heading' => 'Noch unentschlossen, :name?',
    'recovery_intro' => 'Sie haben eine Buchung im :hotel für :date begonnen und nicht abgeschlossen. Das Zimmer ist noch frei — dort weiterzumachen, wo Sie aufgehört haben, dauert eine Minute.',
    'recovery_button' => 'Buchung abschließen',
    'recovery_outro' => 'Falls Sie es sich anders überlegt haben, müssen Sie nichts tun: Es wurde nichts berechnet und nichts reserviert. Wir schreiben Ihnen dazu nicht noch einmal.',

    // Online check-in (§12)
    'pre_arrival_checkin' => 'Sparen Sie Zeit an der Rezeption: Checken Sie auf Ihrer Buchungsseite online ein, dann unterschreiben Sie bei der Ankunft nur noch.',
    'post_stay_book_again' => 'Buchen Sie beim nächsten Mal direkt bei uns unter :url — als Stammgast erhalten Sie :percent% Rabatt.',

    // Cancellation, to the guest; and the hotel's own copy of the news.
    'cancelled_subject' => 'Ihre Buchung :reference im :hotel wurde storniert',
    'cancelled_heading' => 'Ihre Buchung ist storniert',
    'cancelled_intro' => 'Hiermit bestätigen wir, dass Ihr Aufenthalt im :hotel storniert wurde. Sie müssen nichts weiter tun.',
    'cancelled_voucher' => ':amount wurden Ihrem Gutschein wieder gutgeschrieben und können erneut eingelöst werden.',
    'cancelled_refund' => 'Nach den Bedingungen Ihres Tarifs erhalten Sie :amount zurück. Die Erstattung erfolgt auf dem Weg, auf dem Sie bezahlt haben; bei Kartenzahlungen dauert es meist einige Werktage, bis sie sichtbar ist.',
    'cancelled_no_refund' => 'Nach den Bedingungen Ihres Tarifs sind die bereits gezahlten :amount nicht erstattungsfähig.',
    'cancelled_nothing_paid' => 'Es wurde noch nichts bezahlt, daher gibt es nichts zu erstatten.',
    'cancelled_outro' => 'Schade, dass es diesmal nicht klappt – wir würden uns freuen, Sie ein anderes Mal im :hotel zu begrüßen.',
    'notice_booked_subject' => 'Neue Buchung :reference – :name, Anreise :date',
    'notice_cancelled_subject' => 'Storniert: :reference – :name, :date',
    'notice_booked_heading' => 'Eine neue Buchung',
    'notice_cancelled_heading' => 'Eine Buchung wurde storniert',
    'notice_nights' => '{1} :count Nacht|[2,*] :count Nächte',
    'notice_guests' => '{1} :count Gast|[2,*] :count Gäste',
    'notice_paid' => 'bisher bezahlt: :amount',
    'notice_requests' => 'Der Gast schrieb:',
    'notice_voucher_restored' => ':amount wurden automatisch dem Gutschein wieder gutgeschrieben.',
    'notice_refund_owed' => 'Es ist eine Erstattung von :amount fällig. Öffnen Sie die Buchung, um sie zu veranlassen.',
    'notice_no_refund' => 'Es ist nichts zu erstatten: Die gezahlten :amount sind nach dem gebuchten Tarif nicht erstattungsfähig.',
    'notice_open' => 'Buchung öffnen',
];
