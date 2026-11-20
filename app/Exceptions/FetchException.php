<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class FetchException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public ?int $retryAfter = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
