<?php

namespace App\Exceptions;

use App\Enums\RouterErrorCategory;
use Throwable;

class RouterProbeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly RouterErrorCategory $category,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromThrowable(Throwable $exception): self
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);

        $category = match (true) {
            str_contains($lower, 'authentication failed'),
            str_contains($lower, 'http 401') => RouterErrorCategory::Authentication,
            str_contains($lower, 'http 403'),
            str_contains($lower, 'not enough permissions') => RouterErrorCategory::Authorization,
            str_contains($lower, 'certificate'),
            str_contains($lower, 'ssl'),
            str_contains($lower, 'tls') => RouterErrorCategory::Tls,
            str_contains($lower, 'timed out'),
            str_contains($lower, 'timeout') => RouterErrorCategory::Timeout,
            str_contains($lower, 'could not resolve'),
            str_contains($lower, 'name or service not known'),
            str_contains($lower, 'getaddrinfo') => RouterErrorCategory::Dns,
            str_contains($lower, 'connection refused') => RouterErrorCategory::ConnectionRefused,
            str_contains($lower, 'routeros rest api error'),
            str_contains($lower, 'http 4'),
            str_contains($lower, 'http 5') => RouterErrorCategory::Http,
            str_contains($lower, 'json'),
            str_contains($lower, 'invalid response') => RouterErrorCategory::InvalidResponse,
            default => RouterErrorCategory::Unknown,
        };

        return new self($message, $category, $exception);
    }

    public static function configuration(string $message): self
    {
        return new self($message, RouterErrorCategory::Configuration);
    }
}
