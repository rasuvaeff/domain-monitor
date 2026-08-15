<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = $argv[1] ?? 'example.com';

$breakers = [];

$breakerFor = static function (string $host) use (&$breakers): CircuitBreaker {
    if (!isset($breakers[$host])) {
        $breakers[$host] = new CircuitBreaker(
            config: new BreakerConfig(
                name: "domain-monitor:{$host}",
                failureThreshold: Ratio::of(failures: 2, window: 5, within: Duration::seconds(60)),
                cooldown: Duration::seconds(60),
                successThreshold: 1,
                isFailure: static fn(\Throwable $exception): bool => true,
                classifyResult: static fn(mixed $result): Outcome => $result instanceof DomainHealthReport && $result->getStatus() === CheckStatus::CRITICAL
                    ? Outcome::Failure
                    : Outcome::Success,
            ),
            storage: new InMemoryStorage(),
        );
    }

    return $breakers[$host];
};

$monitor = new DomainMonitor(
    dns: new DnsService(),
    port: new PortService(),
);

foreach (range(1, 3) as $round) {
    $report = $monitor->check(
        host: $host,
        options: new DomainMonitorOptions(
            circuitBreaker: $breakerFor($host),
            timeout: Duration::seconds(5),
        ),
    );

    echo "Round {$round}: {$report->getStatus()->value}\n";

    foreach ($report->getErrors() as $error) {
        echo "  {$error->check->value}: {$error->message}\n";
    }
}
