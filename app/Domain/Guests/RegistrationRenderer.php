<?php

declare(strict_types=1);

namespace App\Domain\Guests;

use App\Models\Booking;
use App\Support\Hotel\HotelSettings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The registration form as the desk prints it: everything the guest typed
 * at home, and a line for the one thing that cannot be done online.
 */
class RegistrationRenderer
{
    public function __construct(protected HotelSettings $hotel) {}

    public function render(Booking $booking): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('registrations.document', [
            'booking' => $booking,
            'registration' => $booking->registration,
            'hotel' => $this->hotel,
            'locale' => $booking->locale,
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
