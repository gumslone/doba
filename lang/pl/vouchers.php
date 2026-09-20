<?php

declare(strict_types=1);

/*
 * Gift vouchers (§8), guest-facing: the order page, the two mails, the
 * printed voucher, and redemption on the booking page.
 */

return [
    'one' => 'Voucher podarunkowy',
    'title' => 'Vouchery podarunkowe',
    'meta_description' => 'Podaruj pobyt w :hotel: voucher na dowolną kwotę, ważny przez lata, do wykorzystania online przy każdej rezerwacji.',
    'lede' => 'Pobyt to lepszy prezent niż kolejna rzecz. Wybierz kwotę; voucher można wykorzystać na dowolny pokój, jednorazowo lub przy kilku pobytach.',
    'amount' => 'Kwota',
    'amount_other' => 'Inna kwota',
    'recipient_name' => 'Dla kogo?',
    'recipient_hint' => 'Imię i nazwisko drukowane na voucherze. Zostaw puste, aby wpisać je odręcznie.',
    'message' => 'Kilka słów na voucherze',
    'message_hint' => 'Opcjonalnie — drukowane dokładnie tak, jak wpiszesz.',
    'buyer_name' => 'Twoje imię i nazwisko',
    'buyer_email' => 'Twój e-mail',
    'order' => 'Zamów voucher',
    'how_title' => 'Jak to działa',
    'how_1' => 'Zamawiasz tutaj, a my wysyłamy e-mailem dane do płatności.',
    'how_2' => 'Gdy tylko wpłynie płatność, voucher trafia do Ciebie jako PDF do wydrukowania lub przesłania dalej.',
    'how_3' => 'Realizuje się go kodem podczas rezerwacji na tej stronie lub w recepcji.',
    'ordered_title' => 'Dziękujemy — zamówienie dotarło',
    'ordered_body' => 'Dane do płatności wysłaliśmy na adres :email. Voucher stanie się ważny i zostanie wysłany, gdy tylko wpłynie płatność.',
    'code' => 'Kod vouchera',
    'value' => 'Wartość',
    'valid_until' => 'Ważny do',
    'for' => 'Dla',
    'from' => 'Od',
    'redeem_title' => 'Zapłać voucherem',
    'redeem_hint' => 'Wpisz kod wydrukowany na voucherze. Zostanie odjęty od kwoty do zapłaty; reszta pozostaje na voucherze.',
    'redeem_label' => 'Kod vouchera',
    'redeem_button' => 'Zrealizuj',
    'redeemed' => 'Z vouchera pobrano :amount.',
    'remaining' => 'Na voucherze pozostało: :amount.',
    'error_unknown' => 'Nie znamy takiego kodu. Sprawdź go na voucherze.',
    'error_unpaid' => 'Ten voucher nie został jeszcze opłacony, więc nie można go użyć.',
    'error_void' => 'Ten voucher został anulowany.',
    'error_expired' => 'Ten voucher stracił ważność.',
    'error_empty' => 'Ten voucher został już w całości wykorzystany.',
    'error_currency' => 'Ten voucher jest w innej walucie niż rezerwacja.',
    'error_nothing_due' => 'Przy tej rezerwacji nie ma już nic do zapłaty.',
    'error_booking_closed' => 'Tej rezerwacji nie można już opłacić.',
    'error_not_pending' => 'Ten voucher nie oczekuje na płatność.',
    'error_amount' => 'Wybierz kwotę od :min do :max.',
    'mail_ordered_subject' => 'Zamówienie vouchera w :hotel',
    'mail_ordered_intro' => 'Dziękujemy, :name. Zarezerwowaliśmy dla Ciebie voucher :code na kwotę :amount.',
    'mail_ordered_instructions' => 'Płatność',
    'mail_ordered_outro' => 'Voucher zostanie wysłany, gdy tylko wpłynie płatność. W tytule przelewu podaj kod vouchera.',
    'mail_issued_subject' => 'Twój voucher do :hotel',
    'mail_issued_intro' => 'Dziękujemy, :name — płatność dotarła. Voucher na kwotę :amount jest w załączniku jako PDF, gotowy do wydruku lub przesłania dalej.',
    'mail_issued_how' => 'Realizuje się go kodem :code podczas rezerwacji na naszej stronie lub w recepcji. Ważny do :date.',
    'pdf_redeem' => 'Do wykorzystania przy rezerwacji na :url lub w recepcji.',
];
