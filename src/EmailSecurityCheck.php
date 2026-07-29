<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class EmailSecurityCheck implements JsonSerializable
{
    /**
     * @param list<string> $dkimSelectorsFound selectors whose `{selector}._domainkey.{host}` TXT resolved to a key
     * @param list<string> $caaRecords CA issuers authorised for the host
     * @param list<string> $mxRecords MX targets in preference order
     */
    public function __construct(
        public CheckStatus $status,
        public bool $hasSpf,
        public ?string $spfRecord = null,
        public bool $hasDmarc = false,
        public ?string $dmarcPolicy = null,
        public bool $hasDkim = false,
        public array $dkimSelectorsFound = [],
        public bool $hasCaa = false,
        public array $caaRecords = [],
        public array $mxRecords = [],
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'hasSpf' => $this->hasSpf,
            'spfRecord' => $this->spfRecord,
            'hasDmarc' => $this->hasDmarc,
            'dmarcPolicy' => $this->dmarcPolicy,
            'hasDkim' => $this->hasDkim,
            'dkimSelectorsFound' => $this->dkimSelectorsFound,
            'hasCaa' => $this->hasCaa,
            'caaRecords' => $this->caaRecords,
            'mxRecords' => $this->mxRecords,
            'reason' => $this->reason,
        ];
    }
}
