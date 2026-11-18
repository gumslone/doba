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

    'post_stay_review_ask' => 'Jeśli masz chwilę: krótka opinia na stronie rezerwacji pomaga innym podróżnym — i nam — bardziej, niż się wydaje. Napisać ją mogą tylko prawdziwi goście.',

    // Unfinished-booking reminder (§13)
    'recovery_subject' => 'Pokój w :hotel jest nadal wolny',
    'recovery_heading' => 'Jeszcze się Pan/Pani zastanawia, :name?',
    'recovery_intro' => 'Rozpoczęto rezerwację w :hotel na :date, ale nie została dokończona. Pokój jest nadal dostępny, a dokończenie zajmie minutę.',
    'recovery_button' => 'Dokończ rezerwację',
    'recovery_outro' => 'Jeśli plany się zmieniły, nie trzeba nic robić: nic nie zostało pobrane i nic nie jest zablokowane. Nie napiszemy w tej sprawie ponownie.',

    // Online check-in (§12)
    'pre_arrival_checkin' => 'Oszczędź czas w recepcji: zamelduj się online na stronie rezerwacji, a po przyjeździe wystarczy podpis.',
    'post_stay_book_again' => 'Następnym razem zarezerwuj bezpośrednio u nas na :url — jako stały gość otrzymasz :percent% zniżki.',

    // Cancellation, to the guest; and the hotel's own copy of the news.
    'cancelled_subject' => 'Rezerwacja :reference w :hotel została anulowana',
    'cancelled_heading' => 'Rezerwacja została anulowana',
    'cancelled_intro' => 'Potwierdzamy, że pobyt w :hotel został anulowany. Nie trzeba nic więcej robić.',
    'cancelled_voucher' => 'Kwota :amount wróciła na voucher podarunkowy i można ją wykorzystać ponownie.',
    'cancelled_refund' => 'Zgodnie z warunkami taryfy przysługuje Państwu zwrot :amount. Zwrócimy go tą samą drogą, którą dokonano płatności; zwrot na kartę zwykle pojawia się po kilku dniach roboczych.',
    'cancelled_no_refund' => 'Zgodnie z warunkami taryfy wpłacona kwota :amount nie podlega zwrotowi.',
    'cancelled_nothing_paid' => 'Nic nie zostało zapłacone, więc nie ma czego zwracać.',
    'cancelled_outro' => 'Żałujemy, że tym razem się nie zobaczymy, i mamy nadzieję gościć Państwa w :hotel innym razem.',
    'notice_booked_subject' => 'Nowa rezerwacja :reference — :name, przyjazd :date',
    'notice_cancelled_subject' => 'Anulowano: :reference — :name, :date',
    'notice_booked_heading' => 'Nowa rezerwacja',
    'notice_cancelled_heading' => 'Rezerwacja została anulowana',
    'notice_nights' => '{1} :count noc|[2,4] :count noce|[5,*] :count nocy',
    'notice_guests' => '{1} :count gość|[2,*] :count gości',
    'notice_paid' => 'dotychczas zapłacono: :amount',
    'notice_requests' => 'Gość napisał:',
    'notice_voucher_restored' => 'Kwota :amount automatycznie wróciła na voucher podarunkowy.',
    'notice_refund_owed' => 'Należy zwrócić :amount. Otwórz rezerwację, aby wykonać zwrot.',
    'notice_no_refund' => 'Nic nie trzeba zwracać: wpłacona kwota :amount jest bezzwrotna zgodnie z zarezerwowaną taryfą.',
    'notice_open' => 'Otwórz rezerwację',
];
