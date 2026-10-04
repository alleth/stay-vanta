<?php
declare(strict_types=1);

namespace App\Model\Finance;

use RuntimeException;

/**
 * A refund that was already recorded: the same request again (same
 * idempotency key), or a second policy refund for one cancellation. Nothing
 * is recorded; the API answers 409.
 */
class DuplicateRefundException extends RuntimeException
{
}
