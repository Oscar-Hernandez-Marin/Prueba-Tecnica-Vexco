<?php
declare(strict_types=1);

namespace ViesSync;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
