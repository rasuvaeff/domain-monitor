<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\CheckError;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\HttpContentCheck;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\ReportThresholds;
use Rasuvaeff\DomainMonitor\RobotsTxtCheck;
use Rasuvaeff\DomainMonitor\SecurityHeadersCheck;
use Rasuvaeff\DomainMonitor\SitemapCheck;
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

    public function mapsWhoisStatusAsWarningWithin30Days(): void
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

    public function mapsWhoisStatusAsWarningAtExactlyZeroDays(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            whois: new TldInfo(
                domain: 'example.com',
                expirationDate: new DateTimeImmutable(datetime: 'now'),
            ),
        );

        Assert::same($report->getStatus(), CheckStatus::WARNING);
    }

    public function getChecksReturnsOneResultPerNonNullCheckInOrder(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            dns: new DnsRecords(a: ['1.2.3.4']),
        );

        $checks = $report->getChecks();

        Assert::count($checks, 2);
        Assert::same($checks[0]->check, CheckName::Probe);
        Assert::same($checks[1]->check, CheckName::Dns);
    }

    public function getCheckFindsByNameOrReturnsNull(): void
    {
        $report = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: 200, totalTime: 0.1));

        $probe = $report->getCheck(name: CheckName::Probe);

        Assert::notNull($probe);
        Assert::same($probe->status, CheckStatus::OK);
        Assert::null($report->getCheck(name: CheckName::Ssl));
    }

    #[DataProvider('probeReasonProvider')]
    public function buildsProbeReason(int $status, string $needle): void
    {
        $report = new DomainHealthReport(host: 'example.com', probe: new ProbeResult(status: $status, totalTime: 0.1));

        $check = $report->getCheck(name: CheckName::Probe);

        Assert::notNull($check);
        Assert::string($check->reason)->contains($needle);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function probeReasonProvider(): iterable
    {
        yield 'network failure' => [0, 'Connection failed or no response'];
        yield 'server error' => [503, 'Server error (HTTP 503)'];
        yield 'client error' => [404, 'Client error (HTTP 404)'];
        yield 'ok' => [200, 'HTTP 200'];
    }

    public function dnsReasonCountsPresentRecordTypes(): void
    {
        $report = new DomainHealthReport(host: 'example.com', dns: new DnsRecords(a: ['1.2.3.4'], mx: ['mail.example.com']));

        $check = $report->getCheck(name: CheckName::Dns);

        Assert::notNull($check);
        Assert::same($check->status, CheckStatus::OK);
        Assert::string($check->reason)->contains('2 record type(s) present');
    }

    public function dnsReasonWhenNoRecords(): void
    {
        $report = new DomainHealthReport(host: 'example.com', dns: new DnsRecords());

        $check = $report->getCheck(name: CheckName::Dns);

        Assert::notNull($check);
        Assert::string($check->reason)->contains('No DNS records found');
    }

    public function sslExpiredReasonReportsDaysSinceExpiry(): void
    {
        $report = new DomainHealthReport(host: 'example.com', ssl: $this->certificate(validUntil: '2000-01-01T00:00:00+00:00'));

        $check = $report->getCheck(name: CheckName::Ssl);

        Assert::notNull($check);
        Assert::same($check->status, CheckStatus::CRITICAL);
        Assert::string($check->reason)->contains('Certificate expired');
    }

    public function sslWarnDaysFlipsOkToWarning(): void
    {
        $ssl = new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '-10 days'),
            validUntil: new DateTimeImmutable(datetime: '+10 days'),
            subjectCn: 'example.com',
        );

        $default = new DomainHealthReport(host: 'example.com', ssl: $ssl);
        $strict = new DomainHealthReport(host: 'example.com', ssl: $ssl, thresholds: new ReportThresholds(sslWarnDays: 30));

        Assert::same($default->getCheck(name: CheckName::Ssl)?->status, CheckStatus::OK);
        Assert::same($strict->getCheck(name: CheckName::Ssl)?->status, CheckStatus::WARNING);
    }

    public function customWhoisWarnDaysWidensWarningWindow(): void
    {
        $whois = new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '+40 days'));

        $default = new DomainHealthReport(host: 'example.com', whois: $whois);
        $wide = new DomainHealthReport(host: 'example.com', whois: $whois, thresholds: new ReportThresholds(whoisWarnDays: 60));

        Assert::same($default->getCheck(name: CheckName::Whois)?->status, CheckStatus::OK);
        Assert::same($wide->getCheck(name: CheckName::Whois)?->status, CheckStatus::WARNING);
    }

    #[DataProvider('contentReasonProvider')]
    public function buildsContentReason(HttpContentCheck $content, string $needle): void
    {
        $report = new DomainHealthReport(host: 'example.com', content: $content);

        $check = $report->getCheck(name: CheckName::Content);

        Assert::notNull($check);
        Assert::string($check->reason)->contains($needle);
    }

    /**
     * @return iterable<string, array{HttpContentCheck, string}>
     */
    public static function contentReasonProvider(): iterable
    {
        yield 'forbidden present' => [
            new HttpContentCheck(status: CheckStatus::CRITICAL, httpStatus: 200, finalUrl: null, requiredTextFound: true, forbiddenTextFound: true),
            'Forbidden text present',
        ];
        yield 'required missing' => [
            new HttpContentCheck(status: CheckStatus::CRITICAL, httpStatus: 200, finalUrl: null, requiredTextFound: false, forbiddenTextFound: false),
            'Required text missing',
        ];
        yield 'unexpected status' => [
            new HttpContentCheck(status: CheckStatus::CRITICAL, httpStatus: 500, finalUrl: null, requiredTextFound: true, forbiddenTextFound: false),
            'Unexpected HTTP 500',
        ];
        yield 'content ok' => [
            new HttpContentCheck(status: CheckStatus::OK, httpStatus: 200, finalUrl: null, requiredTextFound: true, forbiddenTextFound: false),
            'Content OK (HTTP 200)',
        ];
    }

    public function portReasonReflectsReachability(): void
    {
        $reachable = new DomainHealthReport(
            host: 'example.com',
            port: new PortCheck(status: CheckStatus::OK, host: 'example.com', port: 443, connectTime: 0.04),
        );
        $closed = new DomainHealthReport(
            host: 'example.com',
            port: new PortCheck(status: CheckStatus::CRITICAL, host: 'example.com', port: 443, connectTime: 0.0, error: 'refused'),
        );

        Assert::string($reachable->getCheck(name: CheckName::Port)?->reason ?? '')->contains('Port 443 reachable');
        Assert::string($closed->getCheck(name: CheckName::Port)?->reason ?? '')->contains('Port closed or unreachable: refused');
    }

    public function securityHeadersReasonListsMissingHeaders(): void
    {
        $missing = new DomainHealthReport(
            host: 'example.com',
            securityHeaders: new SecurityHeadersCheck(
                status: CheckStatus::WARNING,
                hasHsts: false,
                hasContentSecurityPolicy: false,
                hasXFrameOptions: false,
                hasXContentTypeOptions: true,
                presentHeaders: ['X-Content-Type-Options'],
                missingHeaders: ['Strict-Transport-Security', 'Content-Security-Policy'],
            ),
        );
        $complete = new DomainHealthReport(
            host: 'example.com',
            securityHeaders: new SecurityHeadersCheck(
                status: CheckStatus::OK,
                hasHsts: true,
                hasContentSecurityPolicy: true,
                hasXFrameOptions: true,
                hasXContentTypeOptions: true,
                presentHeaders: ['Strict-Transport-Security'],
                missingHeaders: [],
            ),
        );

        Assert::string($missing->getCheck(name: CheckName::SecurityHeaders)?->reason ?? '')
            ->contains('Missing headers: Strict-Transport-Security, Content-Security-Policy');
        Assert::string($complete->getCheck(name: CheckName::SecurityHeaders)?->reason ?? '')
            ->contains('All monitored security headers present');
    }

    public function robotsAndSitemapReasonsReflectExistence(): void
    {
        $robots = new DomainHealthReport(
            host: 'example.com',
            robotsTxt: new RobotsTxtCheck(status: CheckStatus::OK, httpStatus: 200, exists: true, sitemaps: ['https://example.com/sitemap.xml']),
        );
        $sitemap = new DomainHealthReport(
            host: 'example.com',
            sitemap: new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: true, urlCount: 42),
        );

        Assert::string($robots->getCheck(name: CheckName::RobotsTxt)?->reason ?? '')->contains('robots.txt found (1 sitemap hint(s))');
        Assert::string($sitemap->getCheck(name: CheckName::Sitemap)?->reason ?? '')->contains('Sitemap found (42 URL(s))');
    }

    public function erroredChecksAppearAsUnknownResultsAndAreQueryable(): void
    {
        $errors = [new CheckError(check: CheckName::Ssl, message: 'boom')];
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            errors: $errors,
        );

        Assert::true($report->hasErrors());
        Assert::same($report->getErrors(), $errors);

        $sslCheck = $report->getCheck(name: CheckName::Ssl);

        Assert::notNull($sslCheck);
        Assert::same($sslCheck->status, CheckStatus::UNKNOWN);
        Assert::string($sslCheck->reason)->contains('Check failed: boom');
    }

    public function reportWithoutErrorsReportsNoErrors(): void
    {
        Assert::false((new DomainHealthReport(host: 'example.com'))->hasErrors());
    }

    public function erroredCheckDoesNotInflateAggregateStatus(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            errors: [new CheckError(check: CheckName::Ssl, message: 'boom')],
        );

        Assert::same($report->getStatus(), CheckStatus::OK);
    }

    public function serializesTopLevelStructure(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            dns: new DnsRecords(a: ['1.2.3.4']),
            errors: [new CheckError(check: CheckName::Ssl, message: 'boom')],
        );

        $json = $report->jsonSerialize();

        Assert::same($json['host'], 'example.com');
        Assert::same($json['status'], 'ok');
        Assert::same($json['probe'], $report->probe);
        Assert::same($json['dns'], $report->dns);
        Assert::same($json['ssl'], null);
        Assert::same($json['errors'], $report->errors);
    }

    public function jsonEncodesToNestedStructure(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            errors: [new CheckError(check: CheckName::Ssl, message: 'boom')],
        );

        $encoded = (string) \json_encode($report);

        Assert::string($encoded)->contains('"host":"example.com"');
        Assert::string($encoded)->contains('"check":"probe"');
        Assert::string($encoded)->contains('"reason":"HTTP 200"');
        Assert::string($encoded)->contains('"check":"ssl","message":"boom"');
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
