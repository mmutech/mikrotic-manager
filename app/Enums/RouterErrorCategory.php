<?php

namespace App\Enums;

enum RouterErrorCategory: string
{
    case None = 'none';
    case Dns = 'dns';
    case Timeout = 'timeout';
    case ConnectionRefused = 'connection_refused';
    case Tls = 'tls';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Http = 'http';
    case InvalidResponse = 'invalid_response';
    case Configuration = 'configuration';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No error',
            self::Dns => 'DNS resolution failed',
            self::Timeout => 'Connection timed out',
            self::ConnectionRefused => 'Connection refused',
            self::Tls => 'TLS/certificate error',
            self::Authentication => 'Authentication failed',
            self::Authorization => 'Insufficient router permissions',
            self::Http => 'RouterOS REST error',
            self::InvalidResponse => 'Invalid response',
            self::Configuration => 'Configuration rejected',
            self::Unknown => 'Unknown error',
        };
    }

    public function isCredentialProblem(): bool
    {
        return in_array($this, [self::Authentication, self::Authorization], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case === self::None) {
                continue;
            }

            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
