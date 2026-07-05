<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckError;
use Rasuvaeff\DomainMonitor\CheckName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CheckError::class)]
final class CheckErrorTest
{
    public function exposesConstructorValues(): void
    {
        $error = new CheckError(check: CheckName::Dns, message: 'resolver failed');

        Assert::same($error->check, CheckName::Dns);
        Assert::same($error->message, 'resolver failed');
    }

    public function serializesCheckNameToItsValue(): void
    {
        $error = new CheckError(check: CheckName::Sitemap, message: 'timeout');

        Assert::same(
            $error->jsonSerialize(),
            ['check' => 'sitemap', 'message' => 'timeout'],
        );
    }
}
