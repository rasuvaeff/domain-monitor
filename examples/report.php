<?php

declare(strict_types=1);

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\SslCertificate;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = new DomainHealthReport(
    host: 'example.com',
    probe: new ProbeResult(status: 200, totalTime: 0.13),
    ssl: new SslCertificate(
        validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
        validUntil: new DateTimeImmutable(datetime: '2026-06-01T00:00:00+00:00'),
        subjectCn: 'example.com',
        issuer: 'Example CA',
    ),
    port: new PortCheck(
        status: CheckStatus::OK,
        host: 'example.com',
        port: 443,
        connectTime: 0.05,
    ),
);

var_dump($report->getStatus(), $report);
