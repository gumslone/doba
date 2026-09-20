<?php

declare(strict_types=1);

namespace App\Domain\Vouchers;

use RuntimeException;

/**
 * A voucher could not be used. The message is a lang key: the person
 * reading it is a guest at a checkout, in their own language.
 */
class VoucherException extends RuntimeException {}
