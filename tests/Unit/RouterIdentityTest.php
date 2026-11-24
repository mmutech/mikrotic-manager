<?php

namespace Tests\Unit;

use App\Exceptions\RouterProbeException;
use App\Support\RouterIdentity;
use PHPUnit\Framework\TestCase;

class RouterIdentityTest extends TestCase
{
    public function test_it_uses_the_router_name_when_no_identity_is_provided(): void
    {
        $this->assertSame('Branch Router', RouterIdentity::normalize(null, '  Branch Router  '));
        $this->assertSame('Branch Router', RouterIdentity::normalize('   ', 'Branch Router'));
    }

    public function test_it_trims_an_explicit_identity(): void
    {
        $this->assertSame('JEDC-HQ', RouterIdentity::normalize('  JEDC-HQ  ', 'Branch Router'));
    }

    public function test_it_allows_spaces_and_other_non_control_characters(): void
    {
        $identity = 'MMUTech Lab Router #1';

        $this->assertSame($identity, RouterIdentity::normalize($identity, 'Fallback'));
    }

    public function test_it_rejects_identities_longer_than_routeros_allows(): void
    {
        $this->expectException(RouterProbeException::class);

        RouterIdentity::normalize(str_repeat('a', RouterIdentity::MAX_LENGTH + 1), 'Fallback');
    }

    public function test_it_rejects_control_characters(): void
    {
        $this->expectException(RouterProbeException::class);

        RouterIdentity::normalize("Branch\nRouter", 'Fallback');
    }
}
