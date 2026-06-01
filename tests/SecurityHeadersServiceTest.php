<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;

#[CoversClass(SecurityHeadersService::class)]
final class SecurityHeadersServiceTest extends TestCase
{
    private SecurityHeadersService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = new SecurityHeadersService();
    }

    #[Test]
    public function returnsOkWhenAllHeadersArePresent(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('hasHeader')->willReturn(true);

        $result = $this->service->check(response: $response);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame([], $result->missingHeaders);
    }

    #[Test]
    public function returnsWarningWhenHeadersAreMissing(): void
    {
        $headers = ['Strict-Transport-Security' => true, 'X-Frame-Options' => true];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('hasHeader')->willReturnCallback(
            static fn(string $name): bool => isset($headers[$name]),
        );

        $result = $this->service->check(response: $response);

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame(['Content-Security-Policy', 'X-Content-Type-Options'], $result->missingHeaders);
    }
}
