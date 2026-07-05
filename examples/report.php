<?php

declare(strict_types=1);

use Rasuvaeff\DomainMonitor\CheckError;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\PortCheck;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\ReportThresholds;
use Rasuvaeff\DomainMonitor\SslCertificate;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = new DomainHealthReport(
    host: 'example.com',
    probe: new ProbeResult(status: 200, totalTime: 0.13),
    ssl: new SslCertificate(
        validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
        validUntil: new DateTimeImmutable(datetime: '+10 days'),
        subjectCn: 'example.com',
        issuer: 'Example CA',
    ),
    port: new PortCheck(
        status: CheckStatus::OK,
        host: 'example.com',
        port: 443,
        connectTime: 0.05,
    ),
    // Opt in to "SSL expiring soon = warning"; default() keeps 1.1.x behaviour.
    thresholds: ReportThresholds::strict(),
    // A check that ran but threw is reported instead of silently dropped.
    errors: [new CheckError(check: CheckName::Whois, message: 'WHOIS server timeout')],
);

echo "Aggregate: {$report->getStatus()->value}\n";
echo 'Has errors: ' . ($report->hasErrors() ? 'yes' : 'no') . "\n\n";

foreach ($report->getChecks() as $result) {
    printf("%-16s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
}

echo "\nJSON snapshot:\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
