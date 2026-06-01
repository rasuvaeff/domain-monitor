<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
final readonly class DnsRecords
{
    /**
     * @param string[] $a
     * @param string[] $aaaa
     * @param string[] $mx
     * @param string[] $ns
     * @param string[] $txt
     * @param string[] $cname
     */
    public function __construct(
        public array $a = [],
        public array $aaaa = [],
        public array $mx = [],
        public array $ns = [],
        public array $txt = [],
        public array $cname = [],
    ) {}
}
