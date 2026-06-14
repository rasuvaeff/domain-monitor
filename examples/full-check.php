<?php

declare(strict_types=1);

use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\SslCertificateService;
use Rasuvaeff\DomainMonitor\WhoisService;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = $argv[1] ?? 'example.com';

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$monitor = new DomainMonitor(
    httpProbe: new HttpProbeService(httpClient: $client, requestFactory: $requestFactory),
    ssl: new SslCertificateService(),
    whois: new WhoisService(whois: Factory::get()->createWhois()),
    dns: new DnsService(),
    port: new PortService(),
    securityHeaders: new SecurityHeadersService(),
    robotsTxt: new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory),
    sitemap: new SitemapService(httpClient: $client, requestFactory: $requestFactory),
    content: new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory),
);

$report = $monitor->check(
    host: $host,
    options: new DomainMonitorOptions(
        port: 443,
        timeoutSeconds: 10.0,
        httpMethod: 'GET',
    ),
);

printf(
    "Host: %s\nOverall status: %s\n",
    $report->host,
    $report->getStatus()->value,
);

if ($report->probe !== null) {
    printf("Probe: HTTP %d (%.3fs)\n", $report->probe->status, $report->probe->totalTime);
}

if ($report->ssl !== null) {
    printf(
        "SSL: CN=%s, expires in %d days\n",
        $report->ssl->subjectCn,
        $report->ssl->daysUntilExpiry(),
    );
}

if ($report->whois !== null) {
    $days = $report->whois->daysUntilExpiry();
    printf("WHOIS: registrar=%s, expires in %s days\n", $report->whois->registrar ?? 'unknown', $days ?? '?');
}

if ($report->dns !== null) {
    printf("DNS: A=%d, MX=%d, NS=%d\n", count($report->dns->a), count($report->dns->mx), count($report->dns->ns));
}

if ($report->port !== null) {
    printf("Port %d: %s (%.3fs)\n", $report->port->port, $report->port->status->value, $report->port->connectTime);
}

if ($report->securityHeaders !== null) {
    printf(
        "Security headers: %d/%d present\n",
        count($report->securityHeaders->presentHeaders),
        count($report->securityHeaders->presentHeaders) + count($report->securityHeaders->missingHeaders),
    );
}

if ($report->robotsTxt !== null) {
    printf("robots.txt: exists=%s, sitemaps=%d\n", $report->robotsTxt->exists ? 'yes' : 'no', count($report->robotsTxt->sitemaps));
}

if ($report->sitemap !== null) {
    printf("sitemap.xml: exists=%s, urls=%d\n", $report->sitemap->exists ? 'yes' : 'no', $report->sitemap->urlCount);
}

if ($report->content !== null) {
    printf("Content: %s\n", $report->content->status->value);
}
