<?php

namespace App\Enums;

enum HealthCheckStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case AuthenticationFailed = 'authentication_failed';
    case TlsError = 'tls_error';
    case Timeout = 'timeout';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::AuthenticationFailed => 'Authentication failed',
            self::TlsError => 'TLS error',
            self::Timeout => 'Timed out',
            self::Error => 'Error',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Online;
    }

    public static function fromErrorCategory(RouterErrorCategory $category): self
    {
        return match ($category) {
            RouterErrorCategory::Authentication, RouterErrorCategory::Authorization => self::AuthenticationFailed,
            RouterErrorCategory::Tls => self::TlsError,
            RouterErrorCategory::Timeout => self::Timeout,
            RouterErrorCategory::None => self::Online,
            RouterErrorCategory::ConnectionRefused, RouterErrorCategory::Dns => self::Offline,
            default => self::Error,
        };
    }
}
