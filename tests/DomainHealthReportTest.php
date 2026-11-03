<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\CheckError;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\CookieSecurityCheck;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\EmailSecurityCheck;
use Rasuvaeff\DomainMonitor\HttpContentCheck;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\ReportThresholds;
use Rasuvaeff\DomainMonitor\RobotsTxtCheck;
use Rasuvaeff\DomainMonitor\SecurityHeadersCheck;
use Rasuvaeff\DomainMonitor\SitemapCheck;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Rasuvaeff\DomainMonitor\TldInfo;
use Rasuvaeff\DomainMonitor\TlsCipherCheck;
use Rasuvaeff\Result\Result;
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
        Assert::notNull($report->probe);
        Assert::true($report->probe->isOk());
        Assert::same($report->probe->unwrap(), $probe);
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

    public function defaultThresholdsWarnNearSslExpiryAndLegacyDoesNot(): void
    {
        $ssl = new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '-10 days'),
            validUntil: new DateTimeImmutable(datetime: '+10 days'),
            subjectCn: 'example.com',
        );

        $default = new DomainHealthReport(host: 'example.com', ssl: $ssl);
        $legacy = new DomainHealthReport(host: 'example.com', ssl: $ssl, thresholds: ReportThresholds::legacy());

        Assert::same($default->getCheck(name: CheckName::Ssl)?->status, CheckStatus::WARNING);
        Assert::same($legacy->getCheck(name: CheckName::Ssl)?->status, CheckStatus::OK);
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
        $error = new CheckError(check: CheckName::Ssl, message: 'boom');
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            ssl: Result::err(error: $error),
        );

        Assert::true($report->hasErrors());
        Assert::same($report->getErrors(), [$error]);

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
            ssl: Result::err(error: new CheckError(check: CheckName::Ssl, message: 'boom')),
        );

        Assert::same($report->getStatus(), CheckStatus::OK);
    }

    public function serializesTopLevelStructure(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            dns: new DnsRecords(a: ['1.2.3.4']),
            ssl: Result::err(error: new CheckError(check: CheckName::Ssl, message: 'boom')),
        );

        $json = $report->jsonSerialize();

        Assert::same($json['host'], 'example.com');
        Assert::same($json['status'], 'ok');
        Assert::instanceOf($json['probe'], ProbeResult::class);
        Assert::instanceOf($json['dns'], DnsRecords::class);
        Assert::instanceOf($json['ssl'], CheckError::class);
        Assert::same($json['errors'], $report->getErrors());
    }

    public function jsonEncodesToNestedStructure(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: new ProbeResult(status: 200, totalTime: 0.1),
            ssl: Result::err(error: new CheckError(check: CheckName::Ssl, message: 'boom')),
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

    public function dnsReasonCountsExactlyThePresentRecordTypes(): void
    {
        $report = new DomainHealthReport(host: 'example.com', dns: new DnsRecords(a: ['1.2.3.4'], mx: ['mail.example.com']));

        Assert::same($report->getCheck(name: CheckName::Dns)?->reason, '2 record type(s) present');
    }

    public function robotsTxtAndSitemapReasonsWhenMissing(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            robotsTxt: new RobotsTxtCheck(status: CheckStatus::WARNING, httpStatus: 404, exists: false, sitemaps: []),
            sitemap: new SitemapCheck(status: CheckStatus::WARNING, httpStatus: 404, exists: false, urlCount: 0),
        );

        Assert::same($report->getCheck(name: CheckName::RobotsTxt)?->reason, 'robots.txt not found');
        Assert::same($report->getCheck(name: CheckName::RobotsTxt)?->status, CheckStatus::WARNING);
        Assert::same($report->getCheck(name: CheckName::Sitemap)?->reason, 'Sitemap not found');
        Assert::same($report->getCheck(name: CheckName::Sitemap)?->status, CheckStatus::WARNING);
    }

    public function plainAndWrappedEmailTlsAndCookieChecksProduceTheSameResults(): void
    {
        $email = new EmailSecurityCheck(status: CheckStatus::OK, hasSpf: true);
        $tls = new TlsCipherCheck(status: CheckStatus::OK, tlsVersion: 'TLSv1.3', cipherName: 'AES');
        $cookie = new CookieSecurityCheck(status: CheckStatus::WARNING, reason: 'one insecure');
        $plain = new DomainHealthReport(host: 'example.com', emailSecurity: $email, tlsCipher: $tls, cookieSecurity: $cookie);
        $wrapped = new DomainHealthReport(
            host: 'example.com',
            emailSecurity: Result::ok(value: $email),
            tlsCipher: Result::ok(value: $tls),
            cookieSecurity: Result::ok(value: $cookie),
        );

        Assert::same(
            array_map(static fn($c): string => $c->check->value . '|' . $c->status->value . '|' . $c->reason, $plain->getChecks()),
            array_map(static fn($c): string => $c->check->value . '|' . $c->status->value . '|' . $c->reason, $wrapped->getChecks()),
        );
        Assert::same(count($plain->getChecks()), 3);
        Assert::same($plain->getCheck(name: CheckName::CookieSecurity)?->reason, 'one insecure');
        Assert::same($plain->getStatus(), CheckStatus::WARNING);
    }

    public function erroredEmailTlsAndCookieChecksAreReportedUnderTheirOwnNames(): void
    {
        $report = new DomainHealthReport(
            host: 'example.com',
            emailSecurity: Result::err(error: new CheckError(check: CheckName::EmailSecurity, message: 'e1')),
            tlsCipher: Result::err(error: new CheckError(check: CheckName::TlsCipher, message: 'e2')),
            cookieSecurity: Result::err(error: new CheckError(check: CheckName::CookieSecurity, message: 'e3')),
        );

        foreach ([[CheckName::EmailSecurity, 'e1'], [CheckName::TlsCipher, 'e2'], [CheckName::CookieSecurity, 'e3']] as [$name, $message]) {
            $check = $report->getCheck(name: $name);
            Assert::notNull($check);
            Assert::same($check->status, CheckStatus::UNKNOWN);
            Assert::string($check->reason)->contains($message);
        }
    }

    public function getErrorsListsEveryErroredSlotInDeclarationOrder(): void
    {
        $errors = [
            new CheckError(check: CheckName::Probe, message: 'p'),
            new CheckError(check: CheckName::Ssl, message: 's'),
            new CheckError(check: CheckName::CookieSecurity, message: 'c'),
        ];
        $report = new DomainHealthReport(
            host: 'example.com',
            probe: Result::err(error: $errors[0]),
            ssl: Result::err(error: $errors[1]),
            cookieSecurity: Result::err(error: $errors[2]),
        );

        Assert::same($report->getErrors(), $errors);
    }

    #[DataProvider('emailReasonProvider')]
    public function buildsEmailSecurityReason(EmailSecurityCheck $check, string $expected): void
    {
        $report = new DomainHealthReport(host: 'example.com', emailSecurity: $check);

        Assert::same($report->getCheck(name: CheckName::EmailSecurity)?->reason, $expected);
    }

    /**
     * @return iterable<string, array{EmailSecurityCheck, string}>
     */
    public static function emailReasonProvider(): iterable
    {
        yield 'nothing and no MX' => [new EmailSecurityCheck(status: CheckStatus::OK, hasSpf: false), 'No mail infrastructure; SPF/DMARC not expected'];
        yield 'nothing but MX' => [
            new EmailSecurityCheck(status: CheckStatus::WARNING, hasSpf: false, mxRecords: ['mx.example.com']),
            'Mail accepted but SPF/DMARC missing',
        ];
        yield 'DMARC without a policy' => [
            new EmailSecurityCheck(status: CheckStatus::OK, hasSpf: false, hasDmarc: true, mxRecords: ['mx.example.com']),
            '1 MX; policies: DMARC',
        ];
        yield 'everything' => [
            new EmailSecurityCheck(
                status: CheckStatus::OK,
                hasSpf: true,
                hasDmarc: true,
                dmarcPolicy: 'reject',
                hasDkim: true,
                dkimSelectorsFound: ['a', 'b'],
                hasCaa: true,
                caaRecords: ['0 issue "ca.example"'],
                mxRecords: ['mx1.example.com', 'mx2.example.com'],
            ),
            '2 MX; policies: SPF, DMARC(reject), DKIM(a,b), CAA(1)',
        ];
    }

    #[DataProvider('tlsReasonProvider')]
    public function buildsTlsCipherReason(TlsCipherCheck $check, string $expected): void
    {
        $report = new DomainHealthReport(host: 'example.com', tlsCipher: $check);

        Assert::same($report->getCheck(name: CheckName::TlsCipher)?->reason, $expected);
    }

    /**
     * @return iterable<string, array{TlsCipherCheck, string}>
     */
    public static function tlsReasonProvider(): iterable
    {
        yield 'no metadata, with reason' => [new TlsCipherCheck(status: CheckStatus::UNKNOWN, reason: 'handshake failed'), 'handshake failed'];
        yield 'no metadata, no reason' => [new TlsCipherCheck(status: CheckStatus::UNKNOWN), 'TLS metadata unavailable'];
        yield 'strong cipher' => [new TlsCipherCheck(status: CheckStatus::OK, tlsVersion: 'TLSv1.3', cipherName: 'AES256'), 'TLSv1.3 AES256'];
        yield 'weak cipher' => [
            new TlsCipherCheck(status: CheckStatus::WARNING, tlsVersion: 'TLSv1.2', cipherName: 'RC4', usesWeakCipher: true),
            'TLSv1.2 RC4 (weak)',
        ];
        yield 'version without a cipher name' => [new TlsCipherCheck(status: CheckStatus::OK, tlsVersion: 'TLSv1.3'), 'TLSv1.3 '];
    }

    public function cookieSecurityReasonDefaultsToAnEmptyString(): void
    {
        $report = new DomainHealthReport(host: 'example.com', cookieSecurity: new CookieSecurityCheck(status: CheckStatus::OK));

        Assert::same($report->getCheck(name: CheckName::CookieSecurity)?->reason, '');
    }
}
