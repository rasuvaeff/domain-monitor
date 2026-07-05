<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckResult;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CheckResult::class)]
final class CheckResultTest
{
    public function exposesConstructorValues(): void
    {
        $result = new CheckResult(check: CheckName::Ssl, status: CheckStatus::WARNING, reason: 'expires soon');

        Assert::same($result->check, CheckName::Ssl);
        Assert::same($result->status, CheckStatus::WARNING);
        Assert::same($result->reason, 'expires soon');
    }

    public function serializesEnumsToTheirValues(): void
    {
        $result = new CheckResult(check: CheckName::Whois, status: CheckStatus::CRITICAL, reason: 'expired');

        Assert::same(
            $result->jsonSerialize(),
            ['check' => 'whois', 'status' => 'critical', 'reason' => 'expired'],
        );
    }
}
