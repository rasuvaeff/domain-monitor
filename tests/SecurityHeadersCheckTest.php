<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SecurityHeadersCheck;

#[CoversClass(SecurityHeadersCheck::class)]
final class SecurityHeadersCheckTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $check = new SecurityHeadersCheck(
            status: CheckStatus::WARNING,
            hasHsts: true,
            hasContentSecurityPolicy: false,
            hasXFrameOptions: true,
            hasXContentTypeOptions: false,
            presentHeaders: ['Strict-Transport-Security', 'X-Frame-Options'],
            missingHeaders: ['Content-Security-Policy', 'X-Content-Type-Options'],
        );

        $this->assertSame(CheckStatus::WARNING, $check->status);
        $this->assertTrue($check->hasHsts);
        $this->assertFalse($check->hasContentSecurityPolicy);
        $this->assertTrue($check->hasXFrameOptions);
        $this->assertFalse($check->hasXContentTypeOptions);
        $this->assertSame(['Strict-Transport-Security', 'X-Frame-Options'], $check->presentHeaders);
        $this->assertSame(['Content-Security-Policy', 'X-Content-Type-Options'], $check->missingHeaders);
    }
}
