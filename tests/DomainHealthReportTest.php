<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Rasuvaeff\DomainMonitor\TldInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DomainHealthReport::class)]
final class DomainHealthReportTest
{
    public function returnsUnknownWhenNoChecksArePresent(): void
    {
        Assert::same((new DomainHealthReport(host: 'example.com'))->getStatus(), CheckStatus::UNKNOWN);
    }

    public function preservesRawHostAndDtos(): void
    {
        $probe = new ProbeResult(status: 200, totalTime: 0.1);
        $report = new DomainHealthReport(host: 'example.com', probe: $probe);

        Assert::same($report->host, 'example.com');
        Assert::same($report->probe, $probe);
    }

    #[DataProvider('probeStatusProvider')]
    public function mapsProbeStatus(int $httpStatus, CheckStatus $expected): void
    {
        $report = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $httpStatus, totalTime: 0.1));

        Assert::same($report->getStatus(), $expected);
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

    public function mapsSslStatus(): void
    {
        $valid = new DomainHealthReport(host: 'example.com', ssl: $this->certificate(validUntil: '2999-01-01T00:00:00+00:00'));
        $expired = new DomainHealthReport(host: 'example.com', ssl: $this->certificate(validUntil: '2000-01-01T00:00:00+00:00'));

        Assert::same($valid->getStatus(), CheckStatus::OK);
        Assert::same($expired->getStatus(), CheckStatus::CRITICAL);
    }

    #[DataProvider('whoisStatusProvider')]
    public function mapsWhoisStatus(?string $relativeExpiration, CheckStatus $expected): void
    {
        $expirationDate = $relativeExpiration === null ? null : new DateTimeImmutable(datetime: $relativeExpiration);
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(domain: 'example.com', expirationDate: $expirationDate),
        );

        Assert::same($report->getStatus(), $expected);
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

    public function mapsDnsStatus(): void
    {
        $resolved = new DomainHealthReport(host: 'example.com', dns: new DnsRecords(a: ['1.2.3.4']));
        $empty = new DomainHealthReport(host: 'example.com', dns: new DnsRecords());

        Assert::same($resolved->getStatus(), CheckStatus::OK);
        Assert::same($empty->getStatus(), CheckStatus::CRITICAL);
    }

    #[DataProvider('passThroughStatusProvider')]
    public function passesThroughEvaluatedCheckStatuses(CheckStatus $status): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            port: new PortCheck(status: $status, host: 'example.com', port: 443, connectTime: 0.1),
        );

        Assert::same($report->getStatus(), $status);
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

    #[DataProvider('worstStatusProvider')]
    public function returnsWorstStatusAcrossChecks(int $probeStatus, CheckStatus $portStatus, CheckStatus $expected): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: $probeStatus, totalTime: 0.1),
            port: new PortCheck(status: $portStatus, host: 'example.com', port: 443, connectTime: 0.1),
        );

        Assert::same($report->getStatus(), $expected);
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

        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
    }

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

        Assert::same($report->getStatus(), CheckStatus::WARNING);
    }

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

        Assert::same($report->getStatus(), CheckStatus::OK);
    }

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

        Assert::same($report->getStatus(), CheckStatus::WARNING);
    }

    public function selectsLaterStatusWhenOrderIsEqual(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 400, totalTime: 0.1),
            port: new PortCheck(status: CheckStatus::WARNING, host: 'example.com', port: 443, connectTime: 0.1),
        );

        Assert::same($report->getStatus(), CheckStatus::WARNING);
    }

    public function mapsWhoisStatusAsCriticalAtBoundaryZero(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '-1 day'),
            ),
        );

        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
    }

    public function mapsWhoisStatusAsWarningAtExactlyZeroDays(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '+15 days'),
            ),
        );

        Assert::same($report->getStatus(), CheckStatus::WARNING);
    }

    public function mapsWhoisStatusAsWarningAtExactly30Days(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: '+30 days'),
            ),
        );

        Assert::same($report->getStatus(), CheckStatus::WARNING);
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
