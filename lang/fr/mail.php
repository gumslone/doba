<?php

declare(strict_types=1);

return [
    'booking_subject' => 'Votre réservation :reference est confirmée',
    'booking_intro' => 'Merci, :name — nous serons heureux de vous accueillir.',

    'pre_arrival_subject' => 'Votre séjour à :hotel approche',
    'pre_arrival_heading' => 'À très bientôt, :name',
    'pre_arrival_intro' => 'Nous nous réjouissons de vous accueillir à :hotel le :date.',
    'check_in_from' => 'Arrivée à partir de',
    'address' => 'Adresse',
    'phone' => 'Téléphone',
    'pre_arrival_balance' => 'Il reste :amount à régler pour votre séjour. Vous pouvez le faire en ligne dès maintenant — votre arrivée commencera par une clé plutôt que par un terminal de paiement.',
    'pre_arrival_time_ask' => 'Si vous connaissez déjà votre heure d\'arrivée, indiquez-la sur votre page de réservation — votre chambre sera prête.',
    'pre_arrival_outro' => 'Bon voyage — à bientôt.',

    'post_stay_subject' => 'Merci de votre séjour à :hotel',
    'post_stay_heading' => 'Merci, :name',
    'post_stay_intro' => 'Nous espérons que vous avez passé un excellent séjour à :hotel. Ce fut un plaisir de vous recevoir.',
    'post_stay_invoice' => 'Votre facture est disponible sur votre page de réservation, si le voyage passe en note de frais.',
    'post_stay_outro' => 'Nous serions ravis de vous accueillir à nouveau.',

    'post_stay_review_ask' => 'Si vous avez une minute, un court avis sur votre page de réservation aide les autres voyageurs — et nous — plus qu\'on ne le pense. Seuls les vrais clients peuvent en écrire un.',

    // Unfinished-booking reminder (§13)
    'recovery_subject' => 'Votre chambre à :hotel est toujours libre',
    'recovery_heading' => 'Vous hésitez encore, :name ?',
    'recovery_intro' => 'Vous avez commencé une réservation à :hotel pour le :date sans la terminer. La chambre est toujours disponible, et reprendre là où vous vous étiez arrêté ne prend qu’une minute.',
    'recovery_button' => 'Terminer ma réservation',
    'recovery_outro' => 'Si vous avez changé d’avis, ne faites rien : rien n’a été débité et rien n’est retenu. Nous ne vous écrirons plus à ce sujet.',

    // Online check-in (§12)
    'pre_arrival_checkin' => 'Gagnez du temps à la réception : enregistrez-vous en ligne depuis votre page de réservation, il ne restera qu’à signer à votre arrivée.',
    'post_stay_book_again' => 'La prochaine fois, réservez directement chez nous sur :url — en tant que client fidèle, vous bénéficiez de :percent% de réduction.',

    // Cancellation, to the guest; and the hotel's own copy of the news.
    'cancelled_subject' => 'Votre réservation :reference à :hotel est annulée',
    'cancelled_heading' => 'Votre réservation est annulée',
    'cancelled_intro' => 'Nous vous confirmons que votre séjour à :hotel a été annulé. Vous n’avez rien d’autre à faire.',
    'cancelled_voucher' => ':amount ont été recrédités sur votre bon cadeau et peuvent être réutilisés.',
    'cancelled_refund' => 'Selon les conditions de votre tarif, un remboursement de :amount vous est dû. Il sera effectué par le moyen de paiement utilisé ; pour une carte, comptez généralement quelques jours ouvrés avant qu’il apparaisse.',
    'cancelled_no_refund' => 'Selon les conditions de votre tarif, les :amount déjà réglés ne sont pas remboursables.',
    'cancelled_nothing_paid' => 'Rien n’avait été réglé : il n’y a donc rien à rembourser.',
    'cancelled_outro' => 'Nous regrettons de ne pas vous accueillir cette fois-ci et espérons vous recevoir à :hotel une prochaine fois.',
    'notice_booked_subject' => 'Nouvelle réservation :reference — :name, arrivée le :date',
    'notice_cancelled_subject' => 'Annulée : :reference — :name, :date',
    'notice_booked_heading' => 'Une nouvelle réservation',
    'notice_cancelled_heading' => 'Une réservation a été annulée',
    'notice_nights' => '{1} :count nuit|[2,*] :count nuits',
    'notice_guests' => '{1} :count personne|[2,*] :count personnes',
    'notice_paid' => 'déjà réglé : :amount',
    'notice_requests' => 'Message du client :',
    'notice_voucher_restored' => ':amount ont été recrédités automatiquement sur le bon cadeau.',
    'notice_refund_owed' => 'Un remboursement de :amount est dû. Ouvrez la réservation pour l’effectuer.',
    'notice_no_refund' => 'Rien n’est à rembourser : les :amount réglés ne sont pas remboursables selon le tarif réservé.',
    'notice_open' => 'Ouvrir la réservation',
];
