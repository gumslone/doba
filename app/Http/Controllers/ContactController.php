<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnquiryStatus;
use App\Http\Requests\StoreEnquiryRequest;
use App\Mail\EnquiryReceived;
use App\Models\Enquiry;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function show(Seo $seo, HotelSettings $hotel): View
    {
        $seo->title(__('contact.title'))
            ->description(__('contact.meta_description', ['hotel' => $hotel->name]))
            ->canonical(Localization::route('contact'))
            ->alternates(Localization::alternates('contact'))
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb(__('contact.title'), Localization::route('contact'));

        return view('contact', [
            'timingToken' => StoreEnquiryRequest::timingToken(),
        ]);
    }

    public function submit(StoreEnquiryRequest $request, HotelSettings $hotel): RedirectResponse
    {
        $spam = $request->isSpam();

        // Spam is stored, not dropped: the hotelier can rescue a false
        // positive from the admin panel, and the response is identical
        // either way so a bot learns nothing from the outcome.
        $enquiry = Enquiry::create([
            ...$request->safe()->only(['name', 'email', 'phone', 'message', 'check_in', 'check_out']),
            'locale' => app()->getLocale(),
            'status' => $spam ? EnquiryStatus::Spam : EnquiryStatus::New,
            'ip_address' => $request->ip(),
        ]);

        if (! $spam && ($inbox = $hotel->get('contact.email'))) {
            Mail::to((string) $inbox)->queue(new EnquiryReceived($enquiry));
        }

        return redirect(Localization::route('contact'))
            ->with('enquiry_sent', true);
    }
}
