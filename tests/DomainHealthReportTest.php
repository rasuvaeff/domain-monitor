<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Rasuvaeff\DomainMonitor\TldInfo;

#[CoversClass(DomainHealthReport::class)]
final class DomainHealthReportTest extends TestCase
{
    #[Test]
    public function returnsUnknownWhenNoChecksArePresent(): void
    {
        $this->assertSame(CheckStatus::UNKNOWN, (new DomainHealthReport(host: 'example.com'))->getStatus());
    }

    #[Test]
    public function preservesRawHostAndDtos(): void
    {
        $probe = new ProbeResult(status: 200, totalTime: 0.1);
        $report = new DomainHealthReport(host: 'example.com', probe: $probe);

        $this->assertSame('example.com', $report->host);
        $this->assertSame($probe, $report->probe);
    }

    #[Test]
    #[DataProvider('probeStatusProvider')]
    public function mapsProbeStatus(int $httpStatus, CheckStatus $expected): void
    {
        $report = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $httpStatus, totalTime: 0.1));

        $this->assertSame($expected, $report->getStatus());
    }

    /**
     * @return iterable<string, array{int, CheckStatus}>
     */
    public static function probeStatusProvider(): iterable
    {
        yield 'network failure' => [0, CheckStatus::CRITICAL];
        yield 'server error boundary' => [500, CheckStatus::CRITICAL];
        yield 'client error boundary' => [400, CheckStatus::WARNING];
        yield 'just below client error' => [399, CheckStatus::OK];
        yield 'just below server error' => [499, CheckStatus::WARNING];
        yield 'ok' => [200, CheckStatus::OK];
    }

    #[Test]
    public function mapsSslStatus(): void
    {
        $valid = new DomainHealthReport(host: 'example.com', ssl: $this->certificate(validUntil: '2999-01-01T00:00:00+00:00'));
        $expired = new DomainHealthReport(host: 'example.com', ssl: $this->certificate(validUntil: '2000-01-01T00:00:00+00:00'));

        $this->assertSame(CheckStatus::OK, $valid->getStatus());
        $this->assertSame(CheckStatus::CRITICAL, $expired->getStatus());
    }

    #[Test]
    #[DataProvider('whoisStatusProvider')]
    public function mapsWhoisStatus(?string $relativeExpiration, CheckStatus $expected): void
    {
        $expirationDate = $relativeExpiration === null ? null : new DateTimeImmutable(datetime: $relativeExpiration);
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(domain: 'example.com', expirationDate: $expirationDate),
        );

        $this->assertSame($expected, $report->getStatus());
    }

    /**
     * @return iterable<string, array{?string, CheckStatus}>
     */
    public static function whoisStatusProvider(): iterable
    {
        yield 'no expiration date' => [null, CheckStatus::UNKNOWN];
        yield 'already expired' => ['-5 days', CheckStatus::CRITICAL];
        yield 'expiring soon' => ['+10 days', CheckStatus::WARNING];
        yield 'healthy' => ['+200 days', CheckStatus::OK];
    }

    #[Test]
    public function mapsDnsStatus(): void
    {
        $resolved = new DomainHealthReport(host: 'example.com', dns: new DnsRecords(a: ['1.2.3.4']));
        $empty = new DomainHealthReport(host: 'example.com', dns: new DnsRecords());

        $this->assertSame(CheckStatus::OK, $resolved->getStatus());
        $this->assertSame(CheckStatus::CRITICAL, $empty->getStatus());
    }

    #[Test]
    #[DataProvider('passThroughStatusProvider')]
    public function passesThroughEvaluatedCheckStatuses(CheckStatus $status): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            port: new PortCheck(status: $status, host: 'example.com', port: 443, connectTime: 0.1),
        );

        $this->assertSame($status, $report->getStatus());
    }

    /**
     * @return iterable<string, array{CheckStatus}>
     */
    public static function passThroughStatusProvider(): iterable
    {
        yield 'ok' => [CheckStatus::OK];
        yield 'warning' => [CheckStatus::WARNING];
        yield 'critical' => [CheckStatus::CRITICAL];
        yield 'unknown' => [CheckStatus::UNKNOWN];
    }

    #[Test]
    #[DataProvider('worstStatusProvider')]
    public function returnsWorstStatusAcrossChecks(int $probeStatus, CheckStatus $portStatus, CheckStatus $expected): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: $probeStatus, totalTime: 0.1),
            port: new PortCheck(status: $portStatus, host: 'example.com', port: 443, connectTime: 0.1),
        );

        $this->assertSame($expected, $report->getStatus());
    }

    /**
     * @return iterable<string, array{int, CheckStatus, CheckStatus}>
     */
    public static function worstStatusProvider(): iterable
    {
        yield 'ok and unknown keeps ok' => [200, CheckStatus::UNKNOWN, CheckStatus::OK];
        yield 'ok then critical' => [200, CheckStatus::CRITICAL, CheckStatus::CRITICAL];
        yield 'critical probe then ok port' => [500, CheckStatus::OK, CheckStatus::CRITICAL];
        yield 'ok and warning keeps warning' => [200, CheckStatus::WARNING, CheckStatus::WARNING];
    }

    #[Test]
    public function passesThroughContentStatus(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            content: new \Rasuvaeff\DomainMonitor\HttpContentCheck(
                status: CheckStatus::CRITICAL,
                httpStatus: 200,
                finalUrl: null,
                requiredTextFound: false,
                forbiddenTextFound: false,
            ),
        );

        $this->assertSame(CheckStatus::CRITICAL, $report->getStatus());
    }

    #[Test]
    public function passesThroughSecurityHeadersStatus(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            securityHeaders: new \Rasuvaeff\DomainMonitor\SecurityHeadersCheck(
                status: CheckStatus::WARNING,
                hasHsts: false,
                hasContentSecurityPolicy: false,
                hasXFrameOptions: false,
                hasXContentTypeOptions: false,
                presentHeaders: [],
                missingHeaders: ['X-Frame-Options'],
            ),
        );

        $this->assertSame(CheckStatus::WARNING, $report->getStatus());
    }

    #[Test]
    public function passesThroughRobotsTxtStatus(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            robotsTxt: new \Rasuvaeff\DomainMonitor\RobotsTxtCheck(
                status: CheckStatus::OK,
                httpStatus: 200,
                exists: true,
                sitemaps: [],
            ),
        );

        $this->assertSame(CheckStatus::OK, $report->getStatus());
    }

    #[Test]
    public function passesThroughSitemapStatus(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            sitemap: new \Rasuvaeff\DomainMonitor\SitemapCheck(
                status: CheckStatus::WARNING,
                httpStatus: 404,
                exists: false,
                urlCount: 0,
            ),
        );

        $this->assertSame(CheckStatus::WARNING, $report->getStatus());
    }

    #[Test]
    public function selectsLaterStatusWhenOrderIsEqual(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 400, totalTime: 0.1),
            port: new PortCheck(status: CheckStatus::WARNING, host: 'example.com', port: 443, connectTime: 0.1),
        );

        $this->assertSame(CheckStatus::WARNING, $report->getStatus());
    }

    #[Test]
    public function mapsWhoisStatusAsCriticalAtBoundaryZero(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '-1 day'),
            ),
        );

        $this->assertSame(CheckStatus::CRITICAL, $report->getStatus());
    }

    #[Test]
    public function mapsWhoisStatusAsWarningAtExactlyZeroDays(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '+15 days'),
            ),
        );

        $this->assertSame(CheckStatus::WARNING, $report->getStatus());
    }

    #[Test]
    public function mapsWhoisStatusAsWarningAtExactly30Days(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '+30 days'),
            ),
        );

        $this->assertSame(CheckStatus::WARNING, $report->getStatus());
    }

    private function certificate(string $validUntil): SslCertificate
    {
        return new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2000-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: $validUntil),
            subjectCn: 'example.com',
        );
    }
}
