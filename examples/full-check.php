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
    printf("Probe: HTTP %d (%.3fs)\n", $report->probe->unwrap()->status, $report->probe->unwrap()->totalTime);
}

if ($report->ssl !== null) {
    printf(
        "SSL: CN=%s, expires in %d days\n",
        $report->ssl->unwrap()->subjectCn,
        $report->ssl->unwrap()->daysUntilExpiry(),
    );
}

if ($report->whois !== null) {
    $days = $report->whois->unwrap()->daysUntilExpiry();
    printf("WHOIS: registrar=%s, expires in %s days\n", $report->whois->unwrap()->registrar ?? 'unknown', $days ?? '?');
}

if ($report->dns !== null) {
    printf("DNS: A=%d, MX=%d, NS=%d\n", count($report->dns->unwrap()->a), count($report->dns->unwrap()->mx), count($report->dns->unwrap()->ns));
}

if ($report->port !== null) {
    printf("Port %d: %s (%.3fs)\n", $report->port->unwrap()->port, $report->port->unwrap()->status->value, $report->port->unwrap()->connectTime);
}

if ($report->securityHeaders !== null) {
    printf(
        "Security headers: %d/%d present\n",
        count($report->securityHeaders->unwrap()->presentHeaders),
        count($report->securityHeaders->unwrap()->presentHeaders) + count($report->securityHeaders->unwrap()->missingHeaders),
    );
}

if ($report->robotsTxt !== null) {
    printf("robots.txt: exists=%s, sitemaps=%d\n", $report->robotsTxt->unwrap()->exists ? 'yes' : 'no', count($report->robotsTxt->unwrap()->sitemaps));
}

if ($report->sitemap !== null) {
    printf("sitemap.xml: exists=%s, urls=%d\n", $report->sitemap->unwrap()->exists ? 'yes' : 'no', $report->sitemap->unwrap()->urlCount);
}

if ($report->content !== null) {
    printf("Content: %s\n", $report->content->unwrap()->status->value);
}
