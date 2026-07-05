<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(CheckName::class)]
final class CheckNameTest
{
    #[DataProvider('caseProvider')]
    public function backingValueMatchesOrchestratorLabel(CheckName $case, string $value): void
    {
        Assert::same($case->value, $value);
    }

    /**
     * @return iterable<string, array{CheckName, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'probe' => [CheckName::Probe, 'probe'];
        yield 'ssl' => [CheckName::Ssl, 'ssl'];
        yield 'whois' => [CheckName::Whois, 'whois'];
        yield 'dns' => [CheckName::Dns, 'dns'];
        yield 'content' => [CheckName::Content, 'content'];
        yield 'port' => [CheckName::Port, 'port'];
        yield 'security-headers' => [CheckName::SecurityHeaders, 'security-headers'];
        yield 'robots-txt' => [CheckName::RobotsTxt, 'robots-txt'];
        yield 'sitemap' => [CheckName::Sitemap, 'sitemap'];
    }
}
