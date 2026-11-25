<?php

namespace Tests\Unit;

use App\Support\Ipv4Cidr;
use PHPUnit\Framework\TestCase;

class Ipv4CidrTest extends TestCase
{
    public function test_it_canonicalizes_ipv4_networks_and_bare_addresses(): void
    {
        $this->assertSame('10.20.1.0/24', Ipv4Cidr::normalize(' 10.20.1.15/24 '));
        $this->assertSame('203.0.113.25/32', Ipv4Cidr::normalize('203.0.113.25'));
        $this->assertNull(Ipv4Cidr::normalize('0.0.0.0/0'));
        $this->assertNull(Ipv4Cidr::normalize('10.20.1.0/33'));
        $this->assertNull(Ipv4Cidr::normalize('not-an-ip'));
    }

    public function test_it_deduplicates_normalized_values(): void
    {
        $this->assertSame([
            '10.20.1.0/24',
            '203.0.113.25/32',
        ], Ipv4Cidr::normalizeList([
            '10.20.1.15/24',
            'bad value',
            '203.0.113.25',
            '10.20.1.0/24',
            null,
        ]));
    }

    public function test_it_checks_whether_an_address_belongs_to_a_network(): void
    {
        $this->assertTrue(Ipv4Cidr::contains('10.20.1.0/24', '10.20.1.15'));
        $this->assertTrue(Ipv4Cidr::contains('198.51.100.25', '198.51.100.25'));
        $this->assertFalse(Ipv4Cidr::contains('10.20.1.0/24', '10.20.2.15'));
        $this->assertFalse(Ipv4Cidr::contains('0.0.0.0/0', '10.20.1.15'));
    }

    public function test_it_splits_routeros_available_from_lists(): void
    {
        $this->assertSame(
            ['10.20.1.0/24', '203.0.113.25'],
            Ipv4Cidr::splitList('10.20.1.0/24, 203.0.113.25'),
        );
    }
}
