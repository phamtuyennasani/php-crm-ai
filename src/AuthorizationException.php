<?php

namespace NodeService\CrmAiPhp;

final class AuthorizationException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
