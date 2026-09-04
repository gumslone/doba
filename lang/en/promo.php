<?php

declare(strict_types=1);

return [
    'label' => 'Promo code',
    'placeholder' => 'e.g. SPRING25',
    'hint' => 'Have a code? Enter it here — the discount is applied to your room rate.',

    'type_percent' => 'Percentage off',
    'type_fixed' => 'Fixed amount off',
    'type_free_nights' => 'Free nights',

    'discount' => 'Discount',

    // Said specifically, because "invalid code" sends a guest to a
    // competitor while "this code needs three nights" sends them back to
    // change their dates.
    'error_invalid' => 'We do not recognise that code.',
    'error_expired' => 'That code has expired.',
    'error_not_yet_valid' => 'That code cannot be used yet.',
    'error_stay_window' => 'That code does not apply to these dates.',
    'error_min_nights' => 'That code needs a longer stay.',
    'error_min_total' => 'That code needs a higher booking total.',
    'error_room_type' => 'That code does not apply to this room.',
    'error_used_up' => 'That code has already been fully redeemed.',
    'error_guest_limit' => 'You have already used that code.',
    'error_disabled' => 'This hotel does not run promo codes.',
];
