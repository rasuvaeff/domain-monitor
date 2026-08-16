<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\TldInfo;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Shared callable providers for report-typed properties, referenced from
 * `#[Property(generators: [ReportGenerators::class, '…'])]` — the reusable
 * provider form the attribute accepts since property-testing-testo 0.5.
 */
final class ReportGenerators
{
    /** @return array<string, ArbitraryInterface> */
    public static function single(): array
    {
        return ['report' => self::report()];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function pair(): array
    {
        return [
            'previous' => self::report(),
            'current' => self::report(),
        ];
    }

    /**
     * Builds a DomainHealthReport from a random subset of checks (probe / whois
     * / dns), each independently drawn from representative statuses spanning
     * OK / WARNING / CRITICAL / UNKNOWN / absent. Exercises the comparator over
     * the full reachable state space without coupling to any single check.
     */
    private static function report(): ArbitraryInterface
    {
        $probe = Gen::nullable(Gen::oneOf(
            new ProbeResult(status: 200, totalTime: 0.1),
            new ProbeResult(status: 404, totalTime: 0.1),
            new ProbeResult(status: 500, totalTime: 0.1),
            new ProbeResult(status: 0, totalTime: 0.1),
        ));
        $whois = Gen::nullable(Gen::oneOf(
            new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '+100 days')),
            new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '+10 days')),
            new TldInfo(domain: 'example.com', expirationDate: new DateTimeImmutable(datetime: '-1 day')),
            new TldInfo(domain: 'example.com'),
        ));
        $dns = Gen::nullable(Gen::oneOf(
            new DnsRecords(a: ['192.0.2.1']),
            new DnsRecords(),
        ));

        return Gen::map(
            Gen::tuple($probe, $whois, $dns),
            static fn(array $parts): DomainHealthReport => new DomainHealthReport(
                host: 'example.com',
                probe: $parts[0],
                whois: $parts[1],
                dns: $parts[2],
            ),
        );
    }
}
