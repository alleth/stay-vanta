<?php
declare(strict_types=1);

namespace App\Event;

use Cake\Http\Exception\BadRequestException;

/**
 * An action that must say why (an elevated permission, a REQUIRES_REASON
 * event type) came without a reason. Answers 400 if it reaches the client.
 */
class ReasonRequiredException extends BadRequestException
{
    /**
     * @param string|null $message Shown to the user.
     */
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'A reason is required for this action.');
    }
}
