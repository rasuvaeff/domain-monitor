<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SecurityHeadersService::class)]
final class SecurityHeadersServiceTest
{
    private SecurityHeadersService $service;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->service = new SecurityHeadersService();
    }

    public function returnsOkWhenAllHeadersArePresent(): void
    {
        $response = new FakeResponse(
            statusCode: 200,
            headers: [
                'Strict-Transport-Security' => 'max-age=1',
                'Content-Security-Policy' => "default-src 'self'",
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        $result = $this->service->check(response: $response);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->missingHeaders, []);
    }

    public function returnsWarningWhenHeadersAreMissing(): void
    {
        $response = new FakeResponse(
            statusCode: 200,
            headers: [
                'Strict-Transport-Security' => 'max-age=1',
                'X-Frame-Options' => 'DENY',
            ],
        );

        $result = $this->service->check(response: $response);

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::same($result->missingHeaders, ['Content-Security-Policy', 'X-Content-Type-Options']);
    }
}
