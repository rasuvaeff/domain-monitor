<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class SecurityHeadersCheck implements JsonSerializable
{
    /**
     * @param string[] $presentHeaders
     * @param string[] $missingHeaders
     */
    public function __construct(
        public CheckStatus $status,
        public bool $hasHsts,
        public bool $hasContentSecurityPolicy,
        public bool $hasXFrameOptions,
        public bool $hasXContentTypeOptions,
        public array $presentHeaders,
        public array $missingHeaders,
    ) {}

    /**
     * @return array{status: string, hasHsts: bool, hasContentSecurityPolicy: bool, hasXFrameOptions: bool, hasXContentTypeOptions: bool, presentHeaders: string[], missingHeaders: string[]}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'hasHsts' => $this->hasHsts,
            'hasContentSecurityPolicy' => $this->hasContentSecurityPolicy,
            'hasXFrameOptions' => $this->hasXFrameOptions,
            'hasXContentTypeOptions' => $this->hasXContentTypeOptions,
            'presentHeaders' => $this->presentHeaders,
            'missingHeaders' => $this->missingHeaders,
        ];
    }
}
