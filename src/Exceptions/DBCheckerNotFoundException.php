<?php
declare(strict_types = 1);

namespace Forensic\Handler\Exceptions;
use Exception;

class DBCheckerNotFoundException extends Exception
{
    public function __construct(string $message, int $code = 0, Exception $previous = null)
    {
        Exception::__construct($message, $code, $previous);
    }
}