<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class DnsRecords implements JsonSerializable
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

    /**
     * @return array{a: string[], aaaa: string[], mx: string[], ns: string[], txt: string[], cname: string[]}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'a' => $this->a,
            'aaaa' => $this->aaaa,
            'mx' => $this->mx,
            'ns' => $this->ns,
            'txt' => $this->txt,
            'cname' => $this->cname,
        ];
    }
}
