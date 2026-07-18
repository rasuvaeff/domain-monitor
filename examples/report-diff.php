<?php

declare(strict_types=1);

use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\ReportComparator;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Rasuvaeff\DomainMonitor\TransitionKind;

require dirname(__DIR__) . '/vendor/autoload.php';

// Your application stores the previous snapshot; here we build two by hand.
$previous = new DomainHealthReport(
    host: 'example.com',
    probe: new ProbeResult(status: 200, totalTime: 0.13),
    ssl: new SslCertificate(
        validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
        validUntil: new DateTimeImmutable(datetime: '+40 days'),
        subjectCn: 'example.com',
        issuer: 'Example CA',
    ),
);

$current = new DomainHealthReport(
    host: 'example.com',
    // probe degraded 200 -> 503, SSL check disappeared (not run this time)
    probe: new ProbeResult(status: 503, totalTime: 2.41),
);

$diff = (new ReportComparator())->compare(previous: $previous, current: $current);

echo 'Has changes: ' . ($diff->hasChanges() ? 'yes' : 'no') . "\n\n";

foreach ($diff->getTransitions() as $transition) {
    printf(
        "%-16s %-11s %s -> %s\n",
        $transition->check->value,
        $transition->kind->value,
        $transition->from?->value ?? '—',
        $transition->to?->value ?? '—',
    );
}

$worst = $diff->worstTransition();

if ($worst !== null && $worst->kind === TransitionKind::Degraded) {
    echo "\nAlert: '{$worst->check->value}' degraded to {$worst->to?->value}\n";
}

echo "\nJSON snapshot:\n";
echo json_encode($diff, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
