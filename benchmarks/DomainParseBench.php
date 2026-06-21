<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Benchmarks;

use Rasuvaeff\DomainMonitor\HostNormalizer;
use Testo\Bench;

/**
 * Compares HostNormalizer::normalizeHost() for a plain hostname vs a full URL,
 * isolating the parse_url + IDN path through different input shapes.
 */
final class DomainParseBench
{
    #[Bench(
        callables: [
            'from-url' => [self::class, 'normalizeFromUrl'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function normalizeFromHost(): string
    {
        return (new HostNormalizer())->normalizeHost('Example.COM');
    }

    public static function normalizeFromUrl(): string
    {
        return (new HostNormalizer())->normalizeHost('https://Example.COM/path?q=1');
    }
}
