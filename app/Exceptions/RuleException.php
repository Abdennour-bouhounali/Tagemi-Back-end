<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule refused the action; the message is shown to staff as is. */
class RuleException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
