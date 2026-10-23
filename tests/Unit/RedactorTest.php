<?php

namespace Tests\Unit;

use App\Support\Redactor;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    public function test_redacts_sensitive_keys_and_scrubs_auth_headers(): void
    {
        $redactor = new Redactor();

        $data = [
            'name' => 'Test',
            'api_password' => 'secret-password',
            'Authorization' => 'Bearer abc123def456',
            'nested' => [
                'token' => 'very-secret',
                'safe' => 'ok',
            ],
        ];

        $redacted = $redactor->redact($data);

        $this->assertSame('[REDACTED]', $redacted['api_password']);
        $this->assertSame('[REDACTED]', $redacted['Authorization']);
        $this->assertSame('[REDACTED]', $redacted['nested']['token']);
        $this->assertSame('ok', $redacted['nested']['safe']);
        $this->assertSame('Test', $redacted['name']);

        $scrubbed = $redactor->scrubString('Basic dGVzdDp0ZXN0 and Bearer xyz789');
        $this->assertStringContainsString('Basic [REDACTED]', $scrubbed);
        $this->assertStringContainsString('Bearer [REDACTED]', $scrubbed);
    }
}
