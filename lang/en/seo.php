<?php

declare(strict_types=1);

/*
 * Default meta for pages that have no editorial meta of their own.
 *
 * These are fallbacks, not the goal: a hotelier writing a real title and
 * description per room in the admin panel will always beat a template. What
 * these guarantee is that an untouched install never ships an empty <title>
 * or lets Google invent a description from the navigation.
 */

return [
    'rooms' => [
        'title' => 'Rooms & suites',
        'description' => 'Browse the rooms and suites at :hotel. Best rate guaranteed when you book direct — no booking fees, free cancellation on flexible rates.',
    ],
    'direct_booking_note' => 'Book direct for the best available rate.',
];
