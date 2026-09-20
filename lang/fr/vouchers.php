<?php

declare(strict_types=1);

/*
 * Gift vouchers (§8), guest-facing: the order page, the two mails, the
 * printed voucher, and redemption on the booking page.
 */

return [
    'one' => 'Bon cadeau',
    'title' => 'Bons cadeaux',
    'meta_description' => 'Offrez un séjour à :hotel : un bon cadeau du montant de votre choix, valable plusieurs années, utilisable en ligne pour toute réservation.',
    'lede' => 'Un séjour est un plus beau cadeau qu’un objet de plus. Choisissez un montant ; le bon est valable pour toutes les chambres, en une fois ou sur plusieurs séjours.',
    'amount' => 'Montant',
    'amount_other' => 'Autre montant',
    'recipient_name' => 'Pour qui est-il ?',
    'recipient_hint' => 'Le nom imprimé sur le bon. Laissez vide pour l’écrire à la main.',
    'message' => 'Quelques mots sur le bon',
    'message_hint' => 'Facultatif — imprimé exactement comme vous l’écrivez.',
    'buyer_name' => 'Votre nom',
    'buyer_email' => 'Votre e-mail',
    'order' => 'Commander le bon',
    'how_title' => 'Comment ça marche',
    'how_1' => 'Vous commandez ici et nous vous envoyons nos coordonnées de paiement par e-mail.',
    'how_2' => 'Dès réception de votre paiement, le bon vous parvient en PDF, à imprimer ou à transférer.',
    'how_3' => 'Il s’utilise avec son code lors d’une réservation sur ce site, ou à la réception.',
    'ordered_title' => 'Merci — votre commande nous est parvenue',
    'ordered_body' => 'Nous avons envoyé nos coordonnées de paiement à :email. Le bon devient valable, et vous est envoyé, dès réception du paiement.',
    'code' => 'Code du bon',
    'value' => 'Valeur',
    'valid_until' => 'Valable jusqu’au',
    'for' => 'Pour',
    'from' => 'De la part de',
    'redeem_title' => 'Payer avec un bon cadeau',
    'redeem_hint' => 'Saisissez le code imprimé sur votre bon. Il est déduit du montant restant dû ; le solde éventuel reste sur le bon.',
    'redeem_label' => 'Code du bon',
    'redeem_button' => 'Utiliser',
    'redeemed' => ':amount ont été prélevés sur votre bon.',
    'remaining' => 'Reste sur le bon : :amount.',
    'error_unknown' => 'Nous ne connaissons pas ce code. Veuillez le vérifier sur le bon.',
    'error_unpaid' => 'Ce bon n’a pas encore été payé et ne peut donc pas être utilisé.',
    'error_void' => 'Ce bon a été annulé.',
    'error_expired' => 'Ce bon a expiré.',
    'error_empty' => 'Ce bon a déjà été entièrement utilisé.',
    'error_currency' => 'Ce bon est dans une autre devise que la réservation.',
    'error_nothing_due' => 'Il ne reste rien à payer pour cette réservation.',
    'error_booking_closed' => 'Cette réservation ne peut plus être payée.',
    'error_not_pending' => 'Ce bon n’est pas en attente de paiement.',
    'error_amount' => 'Veuillez choisir un montant entre :min et :max.',
    'mail_ordered_subject' => 'Votre commande de bon cadeau à :hotel',
    'mail_ordered_intro' => 'Merci, :name. Nous avons réservé pour vous le bon :code d’un montant de :amount.',
    'mail_ordered_instructions' => 'Paiement',
    'mail_ordered_outro' => 'Le bon vous est envoyé dès réception de votre paiement. Merci d’indiquer le code du bon avec votre paiement.',
    'mail_issued_subject' => 'Votre bon cadeau pour :hotel',
    'mail_issued_intro' => 'Merci, :name — votre paiement est arrivé. Le bon de :amount est joint en PDF, prêt à imprimer ou à transférer.',
    'mail_issued_how' => 'Il s’utilise avec le code :code lors d’une réservation sur notre site, ou à la réception. Valable jusqu’au :date.',
    'pdf_redeem' => 'À utiliser lors d’une réservation sur :url ou à la réception.',
];
