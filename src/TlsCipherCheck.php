<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class TlsCipherCheck implements JsonSerializable
{
    /**
     * @param list<string> $weakCipherNames matched cipher substrings from {@see TlsCipherService::WEAK_CIPHER_PATTERNS}
     */
    public function __construct(
        public CheckStatus $status,
        public ?string $tlsVersion = null,
        public ?string $cipherName = null,
        public ?string $cipherVersion = null,
        public bool $usesWeakCipher = false,
        public array $weakCipherNames = [],
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
            'tlsVersion' => $this->tlsVersion,
            'cipherName' => $this->cipherName,
            'cipherVersion' => $this->cipherVersion,
            'usesWeakCipher' => $this->usesWeakCipher,
            'weakCipherNames' => $this->weakCipherNames,
            'reason' => $this->reason,
        ];
    }
}
