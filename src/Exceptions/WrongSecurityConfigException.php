<?php

namespace Apoplavs\Support\AutoDoc\Exceptions;

use Exception;
use Apoplavs\Support\AutoDoc\Services\SwaggerService;
use Throwable;

class WrongSecurityConfigException extends Exception
{
    public function __construct($securityType, $code = 0, Throwable $previous = null)
    {
        $message = "'{$securityType}' is a wrong security type, available: ";

        foreach (SwaggerService::ALLOWED_SECURITY as $allowedSecurity) {
            $message .= " '{$allowedSecurity}' ";
        }

        parent::__construct($message, $code, $previous);
    }
}
