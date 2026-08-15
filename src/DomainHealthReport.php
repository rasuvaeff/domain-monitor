<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;
use Rasuvaeff\Result\Result;

/**
 * @api
 */
final readonly class DomainHealthReport implements JsonSerializable
{
    /** @var Result<ProbeResult, CheckError>|null */
    public ?Result $probe;

    /** @var Result<SslCertificate, CheckError>|null */
    public ?Result $ssl;

    /** @var Result<TldInfo, CheckError>|null */
    public ?Result $whois;

    /** @var Result<DnsRecords, CheckError>|null */
    public ?Result $dns;

    /** @var Result<HttpContentCheck, CheckError>|null */
    public ?Result $content;

    /** @var Result<PortCheck, CheckError>|null */
    public ?Result $port;

    /** @var Result<SecurityHeadersCheck, CheckError>|null */
    public ?Result $securityHeaders;

    /** @var Result<RobotsTxtCheck, CheckError>|null */
    public ?Result $robotsTxt;

    /** @var Result<SitemapCheck, CheckError>|null */
    public ?Result $sitemap;

    /** @var Result<EmailSecurityCheck, CheckError>|null */
    public ?Result $emailSecurity;

    /** @var Result<TlsCipherCheck, CheckError>|null */
    public ?Result $tlsCipher;

    /** @var Result<CookieSecurityCheck, CheckError>|null */
    public ?Result $cookieSecurity;

    /**
     * @param ProbeResult|Result<ProbeResult, CheckError>|null $probe
     * @param SslCertificate|Result<SslCertificate, CheckError>|null $ssl
     * @param TldInfo|Result<TldInfo, CheckError>|null $whois
     * @param DnsRecords|Result<DnsRecords, CheckError>|null $dns
     * @param HttpContentCheck|Result<HttpContentCheck, CheckError>|null $content
     * @param PortCheck|Result<PortCheck, CheckError>|null $port
     * @param SecurityHeadersCheck|Result<SecurityHeadersCheck, CheckError>|null $securityHeaders
     * @param RobotsTxtCheck|Result<RobotsTxtCheck, CheckError>|null $robotsTxt
     * @param SitemapCheck|Result<SitemapCheck, CheckError>|null $sitemap
     * @param EmailSecurityCheck|Result<EmailSecurityCheck, CheckError>|null $emailSecurity
     * @param TlsCipherCheck|Result<TlsCipherCheck, CheckError>|null $tlsCipher
     * @param CookieSecurityCheck|Result<CookieSecurityCheck, CheckError>|null $cookieSecurity
     */
    public function __construct(
        public string $host,
        ProbeResult|Result|null $probe = null,
        SslCertificate|Result|null $ssl = null,
        TldInfo|Result|null $whois = null,
        DnsRecords|Result|null $dns = null,
        HttpContentCheck|Result|null $content = null,
        PortCheck|Result|null $port = null,
        SecurityHeadersCheck|Result|null $securityHeaders = null,
        RobotsTxtCheck|Result|null $robotsTxt = null,
        SitemapCheck|Result|null $sitemap = null,
        public ?ReportThresholds $thresholds = null,
        EmailSecurityCheck|Result|null $emailSecurity = null,
        TlsCipherCheck|Result|null $tlsCipher = null,
        CookieSecurityCheck|Result|null $cookieSecurity = null,
    ) {
        $this->probe = $probe === null ? null : ($probe instanceof Result ? $probe : Result::ok(value: $probe));
        $this->ssl = $ssl === null ? null : ($ssl instanceof Result ? $ssl : Result::ok(value: $ssl));
        $this->whois = $whois === null ? null : ($whois instanceof Result ? $whois : Result::ok(value: $whois));
        $this->dns = $dns === null ? null : ($dns instanceof Result ? $dns : Result::ok(value: $dns));
        $this->content = $content === null ? null : ($content instanceof Result ? $content : Result::ok(value: $content));
        $this->port = $port === null ? null : ($port instanceof Result ? $port : Result::ok(value: $port));
        $this->securityHeaders = $securityHeaders === null ? null : ($securityHeaders instanceof Result ? $securityHeaders : Result::ok(value: $securityHeaders));
        $this->robotsTxt = $robotsTxt === null ? null : ($robotsTxt instanceof Result ? $robotsTxt : Result::ok(value: $robotsTxt));
        $this->sitemap = $sitemap === null ? null : ($sitemap instanceof Result ? $sitemap : Result::ok(value: $sitemap));
        $this->emailSecurity = $emailSecurity === null ? null : ($emailSecurity instanceof Result ? $emailSecurity : Result::ok(value: $emailSecurity));
        $this->tlsCipher = $tlsCipher === null ? null : ($tlsCipher instanceof Result ? $tlsCipher : Result::ok(value: $tlsCipher));
        $this->cookieSecurity = $cookieSecurity === null ? null : ($cookieSecurity instanceof Result ? $cookieSecurity : Result::ok(value: $cookieSecurity));
    }

    /**
     * @return list<CheckResult>
     */
    public function getChecks(): array
    {
        $thresholds = $this->thresholds ?? ReportThresholds::default();
        $results = [];

        if ($this->probe !== null) {
            $results[] = $this->probe->isOk()
                ? $this->probeCheck(probe: $this->probe->unwrap())
                : self::failed(check: CheckName::Probe, error: $this->probe->error());
        }

        if ($this->ssl !== null) {
            $results[] = $this->ssl->isOk()
                ? $this->sslCheck(certificate: $this->ssl->unwrap(), thresholds: $thresholds)
                : self::failed(check: CheckName::Ssl, error: $this->ssl->error());
        }

        if ($this->whois !== null) {
            $results[] = $this->whois->isOk()
                ? $this->whoisCheck(tldInfo: $this->whois->unwrap(), thresholds: $thresholds)
                : self::failed(check: CheckName::Whois, error: $this->whois->error());
        }

        if ($this->dns !== null) {
            $results[] = $this->dns->isOk()
                ? $this->dnsCheck(dnsRecords: $this->dns->unwrap())
                : self::failed(check: CheckName::Dns, error: $this->dns->error());
        }

        if ($this->content !== null) {
            $results[] = $this->content->isOk()
                ? $this->contentCheck(content: $this->content->unwrap())
                : self::failed(check: CheckName::Content, error: $this->content->error());
        }

        if ($this->port !== null) {
            $results[] = $this->port->isOk()
                ? $this->portCheck(portCheck: $this->port->unwrap())
                : self::failed(check: CheckName::Port, error: $this->port->error());
        }

        if ($this->securityHeaders !== null) {
            $results[] = $this->securityHeaders->isOk()
                ? $this->securityHeadersCheck(headers: $this->securityHeaders->unwrap())
                : self::failed(check: CheckName::SecurityHeaders, error: $this->securityHeaders->error());
        }

        if ($this->robotsTxt !== null) {
            $results[] = $this->robotsTxt->isOk()
                ? $this->robotsTxtCheck(robots: $this->robotsTxt->unwrap())
                : self::failed(check: CheckName::RobotsTxt, error: $this->robotsTxt->error());
        }

        if ($this->sitemap !== null) {
            $results[] = $this->sitemap->isOk()
                ? $this->sitemapCheck(sitemap: $this->sitemap->unwrap())
                : self::failed(check: CheckName::Sitemap, error: $this->sitemap->error());
        }

        if ($this->emailSecurity !== null) {
            $results[] = $this->emailSecurity->isOk()
                ? $this->emailSecurityCheck(check: $this->emailSecurity->unwrap())
                : self::failed(check: CheckName::EmailSecurity, error: $this->emailSecurity->error());
        }

        if ($this->tlsCipher !== null) {
            $results[] = $this->tlsCipher->isOk()
                ? $this->tlsCipherCheck(check: $this->tlsCipher->unwrap())
                : self::failed(check: CheckName::TlsCipher, error: $this->tlsCipher->error());
        }

        if ($this->cookieSecurity !== null) {
            $results[] = $this->cookieSecurity->isOk()
                ? $this->cookieSecurityCheck(check: $this->cookieSecurity->unwrap())
                : self::failed(check: CheckName::CookieSecurity, error: $this->cookieSecurity->error());
        }

        return $results;
    }

    public function getCheck(CheckName $name): ?CheckResult
    {
        foreach ($this->getChecks() as $result) {
            if ($result->check === $name) {
                return $result;
            }
        }

        return null;
    }

    public function getStatus(): CheckStatus
    {
        $worst = CheckStatus::UNKNOWN;

        foreach ($this->getChecks() as $result) {
            if ($result->status->severity() > $worst->severity()) {
                $worst = $result->status;
            }
        }

        return $worst;
    }

    /**
     * @return list<CheckError>
     */
    public function getErrors(): array
    {
        $errors = [];

        foreach (
            [
                $this->probe,
                $this->ssl,
                $this->whois,
                $this->dns,
                $this->content,
                $this->port,
                $this->securityHeaders,
                $this->robotsTxt,
                $this->sitemap,
                $this->emailSecurity,
                $this->tlsCipher,
                $this->cookieSecurity,
            ] as $slot
        ) {
            if ($slot !== null && $slot->isErr()) {
                $error = $slot->error();

                if ($error instanceof CheckError) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    public function hasErrors(): bool
    {
        return $this->getErrors() !== [];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'status' => $this->getStatus()->value,
            'checks' => $this->getChecks(),
            'errors' => $this->getErrors(),
            'probe' => self::serialize(slot: $this->probe),
            'ssl' => self::serialize(slot: $this->ssl),
            'whois' => self::serialize(slot: $this->whois),
            'dns' => self::serialize(slot: $this->dns),
            'content' => self::serialize(slot: $this->content),
            'port' => self::serialize(slot: $this->port),
            'securityHeaders' => self::serialize(slot: $this->securityHeaders),
            'robotsTxt' => self::serialize(slot: $this->robotsTxt),
            'sitemap' => self::serialize(slot: $this->sitemap),
            'emailSecurity' => self::serialize(slot: $this->emailSecurity),
            'tlsCipher' => self::serialize(slot: $this->tlsCipher),
            'cookieSecurity' => self::serialize(slot: $this->cookieSecurity),
        ];
    }

    private static function serialize(?Result $slot): mixed
    {
        return $slot?->unwrapOr(default: $slot->error());
    }

    private static function failed(CheckName $check, mixed $error): CheckResult
    {
        $message = $error instanceof CheckError ? $error->message : 'Check failed';

        return new CheckResult(
            check: $check,
            status: CheckStatus::UNKNOWN,
            reason: \sprintf('Check failed: %s', $message),
        );
    }

    private function probeCheck(ProbeResult $probe): CheckResult
    {
        if ($probe->status === 0) {
            return new CheckResult(
                check: CheckName::Probe,
                status: CheckStatus::CRITICAL,
                reason: 'Connection failed or no response',
            );
        }

        if ($probe->status >= 500) {
            return new CheckResult(
                check: CheckName::Probe,
                status: CheckStatus::CRITICAL,
                reason: \sprintf('Server error (HTTP %d)', $probe->status),
            );
        }

        if ($probe->status >= 400) {
            return new CheckResult(
                check: CheckName::Probe,
                status: CheckStatus::WARNING,
                reason: \sprintf('Client error (HTTP %d)', $probe->status),
            );
        }

        return new CheckResult(
            check: CheckName::Probe,
            status: CheckStatus::OK,
            reason: \sprintf('HTTP %d', $probe->status),
        );
    }

    private function sslCheck(SslCertificate $certificate, ReportThresholds $thresholds): CheckResult
    {
        $days = $certificate->daysUntilExpiry();

        if ($certificate->isExpired()) {
            return new CheckResult(
                check: CheckName::Ssl,
                status: CheckStatus::CRITICAL,
                reason: \sprintf('Certificate expired %d day(s) ago', \abs($days)),
            );
        }

        if ($thresholds->sslWarnDays !== null && $certificate->isExpiringWithin(days: $thresholds->sslWarnDays)) {
            return new CheckResult(
                check: CheckName::Ssl,
                status: CheckStatus::WARNING,
                reason: \sprintf('Certificate expires in %d day(s)', $days),
            );
        }

        return new CheckResult(
            check: CheckName::Ssl,
            status: CheckStatus::OK,
            reason: \sprintf('Certificate valid, expires in %d day(s)', $days),
        );
    }

    private function whoisCheck(TldInfo $tldInfo, ReportThresholds $thresholds): CheckResult
    {
        $days = $tldInfo->daysUntilExpiry();

        if ($days === null) {
            return new CheckResult(
                check: CheckName::Whois,
                status: CheckStatus::UNKNOWN,
                reason: 'Domain expiration date unavailable',
            );
        }

        if ($days < 0) {
            return new CheckResult(
                check: CheckName::Whois,
                status: CheckStatus::CRITICAL,
                reason: \sprintf('Domain expired %d day(s) ago', \abs($days)),
            );
        }

        if ($days <= $thresholds->whoisWarnDays) {
            return new CheckResult(
                check: CheckName::Whois,
                status: CheckStatus::WARNING,
                reason: \sprintf('Domain expires in %d day(s)', $days),
            );
        }

        return new CheckResult(
            check: CheckName::Whois,
            status: CheckStatus::OK,
            reason: \sprintf('Domain expires in %d day(s)', $days),
        );
    }

    private function dnsCheck(DnsRecords $dnsRecords): CheckResult
    {
        $groups = [
            $dnsRecords->a,
            $dnsRecords->aaaa,
            $dnsRecords->mx,
            $dnsRecords->ns,
            $dnsRecords->txt,
            $dnsRecords->cname,
        ];

        $present = 0;

        foreach ($groups as $group) {
            if ($group !== []) {
                ++$present;
            }
        }

        if ($present === 0) {
            return new CheckResult(
                check: CheckName::Dns,
                status: CheckStatus::CRITICAL,
                reason: 'No DNS records found',
            );
        }

        return new CheckResult(
            check: CheckName::Dns,
            status: CheckStatus::OK,
            reason: \sprintf('%d record type(s) present', $present),
        );
    }

    private function contentCheck(HttpContentCheck $content): CheckResult
    {
        if ($content->forbiddenTextFound) {
            $reason = 'Forbidden text present';
        } elseif (!$content->requiredTextFound) {
            $reason = 'Required text missing';
        } elseif ($content->status !== CheckStatus::OK) {
            $reason = \sprintf('Unexpected HTTP %d', $content->httpStatus);
        } else {
            $reason = \sprintf('Content OK (HTTP %d)', $content->httpStatus);
        }

        return new CheckResult(check: CheckName::Content, status: $content->status, reason: $reason);
    }

    private function portCheck(PortCheck $portCheck): CheckResult
    {
        if ($portCheck->error !== null) {
            return new CheckResult(
                check: CheckName::Port,
                status: $portCheck->status,
                reason: \sprintf('Port closed or unreachable: %s', $portCheck->error),
            );
        }

        return new CheckResult(
            check: CheckName::Port,
            status: $portCheck->status,
            reason: \sprintf('Port %d reachable in %.3fs', $portCheck->port, $portCheck->connectTime),
        );
    }

    private function securityHeadersCheck(SecurityHeadersCheck $headers): CheckResult
    {
        if ($headers->missingHeaders !== []) {
            return new CheckResult(
                check: CheckName::SecurityHeaders,
                status: $headers->status,
                reason: \sprintf('Missing headers: %s', \implode(', ', $headers->missingHeaders)),
            );
        }

        return new CheckResult(
            check: CheckName::SecurityHeaders,
            status: $headers->status,
            reason: 'All monitored security headers present',
        );
    }

    private function robotsTxtCheck(RobotsTxtCheck $robots): CheckResult
    {
        if (!$robots->exists) {
            return new CheckResult(
                check: CheckName::RobotsTxt,
                status: $robots->status,
                reason: 'robots.txt not found',
            );
        }

        return new CheckResult(
            check: CheckName::RobotsTxt,
            status: $robots->status,
            reason: \sprintf('robots.txt found (%d sitemap hint(s))', \count($robots->sitemaps)),
        );
    }

    private function sitemapCheck(SitemapCheck $sitemap): CheckResult
    {
        if (!$sitemap->exists) {
            return new CheckResult(
                check: CheckName::Sitemap,
                status: $sitemap->status,
                reason: 'Sitemap not found',
            );
        }

        return new CheckResult(
            check: CheckName::Sitemap,
            status: $sitemap->status,
            reason: \sprintf('Sitemap found (%d URL(s))', $sitemap->urlCount),
        );
    }

    private function emailSecurityCheck(EmailSecurityCheck $check): CheckResult
    {
        $parts = [];

        if ($check->hasSpf) {
            $parts[] = 'SPF';
        }

        if ($check->hasDmarc) {
            $parts[] = $check->dmarcPolicy !== null
                ? \sprintf('DMARC(%s)', $check->dmarcPolicy)
                : 'DMARC';
        }

        if ($check->hasDkim) {
            $parts[] = \sprintf('DKIM(%s)', \implode(separator: ',', array: $check->dkimSelectorsFound));
        }

        if ($check->hasCaa) {
            $parts[] = \sprintf('CAA(%d)', \count($check->caaRecords));
        }

        $reason = $parts === []
            ? ($check->mxRecords === [] ? 'No mail infrastructure; SPF/DMARC not expected' : 'Mail accepted but SPF/DMARC missing')
            : \sprintf('%d MX; policies: %s', \count($check->mxRecords), \implode(separator: ', ', array: $parts));

        return new CheckResult(check: CheckName::EmailSecurity, status: $check->status, reason: $reason);
    }

    private function tlsCipherCheck(TlsCipherCheck $check): CheckResult
    {
        $reason = $check->tlsVersion === null
            ? ($check->reason ?? 'TLS metadata unavailable')
            : \sprintf('%s %s%s', $check->tlsVersion, $check->cipherName ?? '', $check->usesWeakCipher ? ' (weak)' : '');

        return new CheckResult(check: CheckName::TlsCipher, status: $check->status, reason: $reason);
    }

    private function cookieSecurityCheck(CookieSecurityCheck $check): CheckResult
    {
        return new CheckResult(check: CheckName::CookieSecurity, status: $check->status, reason: $check->reason ?? '');
    }
}
