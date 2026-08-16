<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stored as a plain string column — the portable subset has no ENUM (§5).
 */
enum EnquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Replied = 'replied';
    case Spam = 'spam';
}
