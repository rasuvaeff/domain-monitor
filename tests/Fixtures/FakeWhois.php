<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Closure;
use Iodev\Whois\Modules\Tld\TldInfo as VendorTldInfo;
use Iodev\Whois\Whois;
use Throwable;

/**
 * @internal
 */
final class FakeWhois extends Whois
{
    private int $callCount = 0;

    /**
     * @param Closure(string): (?VendorTldInfo) $handler
     */
    public function __construct(
        private readonly Closure $handler,
        private readonly ?Throwable $exception = null,
    ) {}

    #[\Override]
    public function loadDomainInfo($domain): ?VendorTldInfo
    {
        $this->callCount++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return ($this->handler)((string) $domain);
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
