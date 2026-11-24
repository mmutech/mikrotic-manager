<?php

namespace Tests\Unit;

use App\Exceptions\RouterProbeException;
use App\Services\Mikrotik\DetailedMikrotikClient;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use PHPUnit\Framework\TestCase;

class DetailedMikrotikClientTest extends TestCase
{
    public function test_it_captures_the_routeros_error_detail_discarded_by_the_vendor_parser(): void
    {
        $client = $this->clientWithCapturedResponse(
            '<<< [REST RESPONSE 400] {"detail":"input does not match any value of interface","error":400,"message":"Bad Request"}',
        );

        $this->assertSame(
            'input does not match any value of interface - Bad Request - 400',
            $client->lastRouterOsError(),
        );
    }

    public function test_it_does_not_treat_a_successful_response_as_an_error(): void
    {
        $client = $this->clientWithCapturedResponse(
            '<<< [REST RESPONSE 200] {".id":"*1","name":"JEDC-HQ"}',
        );

        $this->assertNull($client->lastRouterOsError());
    }

    public function test_it_clears_the_previous_error_when_a_new_request_starts(): void
    {
        $client = $this->clientWithCapturedResponse(
            '<<< [REST RESPONSE 400] {"detail":"old error","error":400,"message":"Bad Request"}',
        );
        $client->capture('>>> [REST] GET /rest/interface');

        $this->assertNull($client->lastRouterOsError());
    }

    public function test_it_includes_the_routeros_detail_in_probe_exception_messages(): void
    {
        $client = $this->clientWithCapturedResponse(
            '<<< [REST RESPONSE 400] {"detail":"input does not match any value of address-pool","error":400,"message":"Bad Request"}',
        );
        $exception = MikrotikException::apiError('400', 400);

        $probeException = RouterProbeException::fromThrowable($exception, $client->lastRouterOsError());

        $this->assertStringContainsString(
            'RouterOS response: input does not match any value of address-pool',
            $probeException->getMessage(),
        );
    }

    private function clientWithCapturedResponse(string $response): TestDetailedMikrotikClient
    {
        $client = new TestDetailedMikrotikClient;
        $client->capture($response);

        return $client;
    }
}

class TestDetailedMikrotikClient extends DetailedMikrotikClient
{
    public function capture(string $message): void
    {
        $this->debug($message);
    }
}
