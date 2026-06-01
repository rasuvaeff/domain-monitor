<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
final readonly class SecurityHeadersCheck
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
}
