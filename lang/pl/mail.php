<?php

declare(strict_types=1);

return [
    'test_subject' => 'Wiadomość testowa od :hotel',
    'test_heading' => 'Twoja poczta działa',
    'test_body' => 'To wiadomość testowa od :hotel. Jeśli ją czytasz, Twój hotel może wysyłać gościom potwierdzenia.',
    'test_instruction' => 'Wpisz ten kod w Admin → Mail, aby potwierdzić, że wiadomość dotarła.',
    'test_signoff' => 'Tę wiadomość można usunąć.',

    'spf_note' => 'Informuje inne serwery pocztowe, że ten host może wysyłać pocztę w imieniu Twojej domeny. Bez tego potwierdzenia najpewniej trafią do spamu.',
    'dmarc_note' => 'Mówi innym serwerom, co zrobić z pocztą, która nie przejdzie weryfikacji. Celowo zaczyna od „quarantine”, a nie „reject”: hotel, który od pierwszego dnia odrzuca pocztę przy lekko błędnym SPF, przestaje dostarczać własne potwierdzenia.',
    'dkim_note' => 'Twój dostawca poczty przekaże Ci tę wartość oraz selektor do podmiany w hoście. Podpis dowodzi, że wiadomości nie zmieniono po drodze.',
    'dkim_value' => '(wartość od Twojego dostawcy poczty)',

    'booking_subject' => 'Twoja rezerwacja :reference jest potwierdzona',
    'booking_intro' => 'Dziękujemy, :name — czekamy na Ciebie z niecierpliwością.',

    'pre_arrival_subject' => 'Zbliża się Twój pobyt w :hotel',
    'pre_arrival_heading' => 'Do zobaczenia, :name',
    'pre_arrival_intro' => 'Cieszymy się, że powitamy Cię w :hotel dnia :date.',
    'check_in_from' => 'Zameldowanie od',
    'address' => 'Adres',
    'phone' => 'Telefon',
    'pre_arrival_balance' => 'Za Twój pobyt pozostało do zapłaty :amount. Możesz uregulować to online już teraz — wtedy przyjazd zacznie się od klucza, a nie od terminala płatniczego.',
    'pre_arrival_time_ask' => 'Jeśli znasz już godzinę przyjazdu, daj nam znać na stronie rezerwacji — Twój pokój będzie gotowy.',
    'pre_arrival_outro' => 'Szerokiej drogi — do zobaczenia.',

    'post_stay_subject' => 'Dziękujemy za pobyt w :hotel',
    'post_stay_heading' => 'Dziękujemy, :name',
    'post_stay_intro' => 'Mamy nadzieję, że pobyt w :hotel był udany. Goszczenie Ciebie było przyjemnością.',
    'post_stay_invoice' => 'Twoja faktura jest na stronie rezerwacji — gdyby podróż trafiła do rozliczenia.',
    'post_stay_outro' => 'Z przyjemnością powitamy Cię ponownie.',
];
