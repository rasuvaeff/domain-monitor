<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SecurityHeadersCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SecurityHeadersCheck::class)]
final class SecurityHeadersCheckTest
{
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

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::true($check->hasHsts);
        Assert::false($check->hasContentSecurityPolicy);
        Assert::true($check->hasXFrameOptions);
        Assert::false($check->hasXContentTypeOptions);
        Assert::same($check->presentHeaders, ['Strict-Transport-Security', 'X-Frame-Options']);
        Assert::same($check->missingHeaders, ['Content-Security-Policy', 'X-Content-Type-Options']);
    }
}
