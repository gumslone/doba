<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use RuntimeException;

class InvalidWebhookException extends RuntimeException {}
