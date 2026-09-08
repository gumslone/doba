<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnquiryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact-form submission — the plan's "the contact form has to land
 * somewhere" (§5). The admin panel's unread-enquiries counter reads from
 * here, so spam is *stored* with its own status rather than dropped:
 * a false positive the hotelier can rescue beats one that vanished.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $locale
 * @property string|null $subject
 * @property string $message
 * @property string|null $reply
 * @property CarbonImmutable|null $check_in
 * @property CarbonImmutable|null $check_out
 * @property EnquiryStatus $status
 * @property int|null $replied_by
 * @property CarbonImmutable|null $replied_at
 * @property CarbonImmutable|null $created_at
 */
class Enquiry extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'locale', 'subject', 'message', 'reply',
        'check_in', 'check_out', 'status', 'replied_by', 'replied_at', 'ip_address',
    ];

    protected $casts = [
        'check_in' => 'immutable_date',
        'check_out' => 'immutable_date',
        'status' => EnquiryStatus::class,
        'replied_at' => 'immutable_datetime',
    ];

    /**
     * Who answered, when it was answered from the panel.
     *
     * @return BelongsTo<User, $this>
     */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /**
     * The guest book entry for this address, if the writer has stayed
     * before — the desk answers a regular differently.
     */
    public function knownGuest(): ?Guest
    {
        return Guest::query()->where('email', $this->email)->first();
    }

    /**
     * Everything a person should see by default: spam is kept, not shown.
     *
     * @param  Builder<Enquiry>  $query
     * @return Builder<Enquiry>
     */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->where('status', '!=', EnquiryStatus::Spam->value);
    }

    public static function unreadCount(): int
    {
        return static::query()->where('status', EnquiryStatus::New->value)->count();
    }
}
