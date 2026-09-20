<?php

declare(strict_types=1);

/*
 * Gift vouchers (§8), guest-facing: the order page, the two mails, the
 * printed voucher, and redemption on the booking page.
 */

return [
    'one' => 'Cadeaubon',
    'title' => 'Cadeaubonnen',
    'meta_description' => 'Geef een verblijf bij :hotel cadeau: een cadeaubon voor elk bedrag, jarenlang geldig, online in te wisselen bij elke boeking.',
    'lede' => 'Een verblijf is een mooier cadeau dan nog een ding. Kies een bedrag; de bon is geldig voor elke kamer, in één keer of verdeeld over meerdere verblijven.',
    'amount' => 'Bedrag',
    'amount_other' => 'Ander bedrag',
    'recipient_name' => 'Voor wie is hij?',
    'recipient_hint' => 'De naam die op de bon staat. Laat leeg om hem met de hand in te vullen.',
    'message' => 'Een paar woorden op de bon',
    'message_hint' => 'Optioneel — wordt precies zo gedrukt als u het schrijft.',
    'buyer_name' => 'Uw naam',
    'buyer_email' => 'Uw e-mailadres',
    'order' => 'Bon bestellen',
    'how_title' => 'Zo werkt het',
    'how_1' => 'U bestelt hier en wij sturen u onze betaalgegevens per e-mail.',
    'how_2' => 'Zodra uw betaling binnen is, ontvangt u de bon als pdf om te printen of door te sturen.',
    'how_3' => 'Inwisselen gaat met de code bij een boeking op deze website, of aan de balie.',
    'ordered_title' => 'Dank u — uw bestelling is binnen',
    'ordered_body' => 'We hebben onze betaalgegevens naar :email gestuurd. De bon wordt geldig, en naar u verzonden, zodra de betaling binnen is.',
    'code' => 'Boncode',
    'value' => 'Waarde',
    'valid_until' => 'Geldig tot',
    'for' => 'Voor',
    'from' => 'Van',
    'redeem_title' => 'Betalen met een cadeaubon',
    'redeem_hint' => 'Typ de code die op uw bon staat. Die wordt afgetrokken van wat nog openstaat; wat overblijft, blijft op de bon.',
    'redeem_label' => 'Boncode',
    'redeem_button' => 'Inwisselen',
    'redeemed' => ':amount is van uw bon afgeschreven.',
    'remaining' => 'Nog op de bon: :amount.',
    'error_unknown' => 'Deze boncode kennen wij niet. Controleer hem op de bon.',
    'error_unpaid' => 'Deze bon is nog niet betaald en kan daarom niet worden gebruikt.',
    'error_void' => 'Deze bon is geannuleerd.',
    'error_expired' => 'Deze bon is verlopen.',
    'error_empty' => 'Deze bon is al helemaal gebruikt.',
    'error_currency' => 'Deze bon is in een andere valuta dan de boeking.',
    'error_nothing_due' => 'Er staat niets meer open voor deze boeking.',
    'error_booking_closed' => 'Deze boeking kan niet meer worden betaald.',
    'error_not_pending' => 'Deze bon wacht niet op betaling.',
    'error_amount' => 'Kies een bedrag tussen :min en :max.',
    'mail_ordered_subject' => 'Uw bestelling van een cadeaubon bij :hotel',
    'mail_ordered_intro' => 'Dank u, :name. We hebben bon :code ter waarde van :amount voor u gereserveerd.',
    'mail_ordered_instructions' => 'Betalen',
    'mail_ordered_outro' => 'De bon wordt naar u verzonden zodra uw betaling binnen is. Vermeld de boncode bij uw betaling.',
    'mail_issued_subject' => 'Uw cadeaubon voor :hotel',
    'mail_issued_intro' => 'Dank u, :name — uw betaling is binnen. De bon ter waarde van :amount zit als pdf in de bijlage, klaar om te printen of door te sturen.',
    'mail_issued_how' => 'Inwisselen gaat met code :code bij een boeking op onze website, of aan de balie. Geldig tot :date.',
    'pdf_redeem' => 'In te wisselen bij een boeking op :url of aan de balie.',
];
