<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnquiryStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact-form submission — the plan's "the contact form has to land
 * somewhere" (§5). The admin panel's unread-enquiries counter reads from
 * here, so spam is *stored* with its own status rather than dropped:
 * a false positive the hotelier can rescue beats one that vanished.
 *
 * @property string $name
 * @property string $email
 * @property EnquiryStatus $status
 */
class Enquiry extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'locale', 'subject', 'message',
        'check_in', 'check_out', 'status', 'ip_address',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'status' => EnquiryStatus::class,
    ];
}
