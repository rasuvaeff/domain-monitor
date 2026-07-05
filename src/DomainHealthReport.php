<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class DomainHealthReport implements JsonSerializable
{
    private const array STATUS_ORDER = [
        CheckStatus::UNKNOWN->value => 0,
        CheckStatus::OK->value => 1,
        CheckStatus::WARNING->value => 2,
        CheckStatus::CRITICAL->value => 3,
    ];

    /**
     * @param list<CheckError> $errors
     */
    public function __construct(
        public string $host,
        public ?ProbeResult $probe = null,
        public ?SslCertificate $ssl = null,
        public ?TldInfo $whois = null,
        public ?DnsRecords $dns = null,
        public ?HttpContentCheck $content = null,
        public ?PortCheck $port = null,
        public ?SecurityHeadersCheck $securityHeaders = null,
        public ?RobotsTxtCheck $robotsTxt = null,
        public ?SitemapCheck $sitemap = null,
        public ?ReportThresholds $thresholds = null,
        public array $errors = [],
    ) {}

    /**
     * @return list<CheckResult>
     */
    public function getChecks(): array
    {
        $thresholds = $this->thresholds ?? ReportThresholds::default();
        $results = [];

        if ($this->probe !== null) {
            $results[] = $this->probeCheck(probe: $this->probe);
        }

        if ($this->ssl !== null) {
            $results[] = $this->sslCheck(certificate: $this->ssl, thresholds: $thresholds);
        }

        if ($this->whois !== null) {
            $results[] = $this->whoisCheck(tldInfo: $this->whois, thresholds: $thresholds);
        }

        if ($this->dns !== null) {
            $results[] = $this->dnsCheck(dnsRecords: $this->dns);
        }

        if ($this->content !== null) {
            $results[] = $this->contentCheck(content: $this->content);
        }

        if ($this->port !== null) {
            $results[] = $this->portCheck(portCheck: $this->port);
        }

        if ($this->securityHeaders !== null) {
            $results[] = $this->securityHeadersCheck(headers: $this->securityHeaders);
        }

        if ($this->robotsTxt !== null) {
            $results[] = $this->robotsTxtCheck(robots: $this->robotsTxt);
        }

        if ($this->sitemap !== null) {
            $results[] = $this->sitemapCheck(sitemap: $this->sitemap);
        }

        foreach ($this->errors as $error) {
            $results[] = new CheckResult(
                check: $error->check,
                status: CheckStatus::UNKNOWN,
                reason: \sprintf('Check failed: %s', $error->message),
            );
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
            if (self::STATUS_ORDER[$result->status->value] > self::STATUS_ORDER[$worst->value]) {
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
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
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
            'errors' => $this->errors,
            'probe' => $this->probe,
            'ssl' => $this->ssl,
            'whois' => $this->whois,
            'dns' => $this->dns,
            'content' => $this->content,
            'port' => $this->port,
            'securityHeaders' => $this->securityHeaders,
            'robotsTxt' => $this->robotsTxt,
            'sitemap' => $this->sitemap,
        ];
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
}
