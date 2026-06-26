<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
final readonly class DomainHealthReport
{
    private const array STATUS_ORDER = [
        CheckStatus::UNKNOWN->value => 0,
        CheckStatus::OK->value => 1,
        CheckStatus::WARNING->value => 2,
        CheckStatus::CRITICAL->value => 3,
    ];

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
    ) {}

    public function getStatus(): CheckStatus
    {
        $statuses = [];

        if ($this->probe !== null) {
            $statuses[] = $this->mapProbeStatus(probe: $this->probe);
        }

        if ($this->ssl !== null) {
            $statuses[] = $this->mapSslStatus(certificate: $this->ssl);
        }

        if ($this->whois !== null) {
            $statuses[] = $this->mapWhoisStatus(tldInfo: $this->whois);
        }

        if ($this->dns !== null) {
            $statuses[] = $this->mapDnsStatus(dnsRecords: $this->dns);
        }

        if ($this->content !== null) {
            $statuses[] = $this->content->status;
        }

        if ($this->port !== null) {
            $statuses[] = $this->port->status;
        }

        if ($this->securityHeaders !== null) {
            $statuses[] = $this->securityHeaders->status;
        }

        if ($this->robotsTxt !== null) {
            $statuses[] = $this->robotsTxt->status;
        }

        if ($this->sitemap !== null) {
            $statuses[] = $this->sitemap->status;
        }

        $worstStatus = CheckStatus::UNKNOWN;

        foreach ($statuses as $status) {
            if (self::STATUS_ORDER[$status->value] > self::STATUS_ORDER[$worstStatus->value]) {
                $worstStatus = $status;
            }
        }

        return $worstStatus;
    }

    private function mapProbeStatus(ProbeResult $probe): CheckStatus
    {
        if ($probe->status === 0 || $probe->status >= 500) {
            return CheckStatus::CRITICAL;
        }

        if ($probe->status >= 400) {
            return CheckStatus::WARNING;
        }

        return CheckStatus::OK;
    }

    private function mapSslStatus(SslCertificate $certificate): CheckStatus
    {
        if ($certificate->isExpired()) {
            return CheckStatus::CRITICAL;
        }

        return CheckStatus::OK;
    }

    private function mapWhoisStatus(TldInfo $tldInfo): CheckStatus
    {
        $daysUntilExpiry = $tldInfo->daysUntilExpiry();

        if ($daysUntilExpiry === null) {
            return CheckStatus::UNKNOWN;
        }

        if ($daysUntilExpiry < 0) {
            return CheckStatus::CRITICAL;
        }

        if ($daysUntilExpiry <= 30) {
            return CheckStatus::WARNING;
        }

        return CheckStatus::OK;
    }

    private function mapDnsStatus(DnsRecords $dnsRecords): CheckStatus
    {
        if (
            $dnsRecords->a === []
            && $dnsRecords->aaaa === []
            && $dnsRecords->mx === []
            && $dnsRecords->ns === []
            && $dnsRecords->txt === []
            && $dnsRecords->cname === []
        ) {
            return CheckStatus::CRITICAL;
        }

        return CheckStatus::OK;
    }
}
