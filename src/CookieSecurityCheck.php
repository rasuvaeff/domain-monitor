<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use JsonSerializable;

/**
 * @api
 */
final readonly class CookieSecurityCheck implements JsonSerializable
{
    /**
     * @param list<array{name: string, secure: bool, httpOnly: bool, sameSite: ?string, path: ?string, domain: ?string, hostPrefixed: bool}> $cookies
     * @param list<string> $insecureCookieNames cookies missing Secure, HttpOnly, or `__Host-`/`__Secure-` prefix rules
     */
    public function __construct(
        public CheckStatus $status,
        public array $cookies = [],
        public array $insecureCookieNames = [],
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
            'cookies' => $this->cookies,
            'insecureCookieNames' => $this->insecureCookieNames,
            'reason' => $this->reason,
        ];
    }
}
