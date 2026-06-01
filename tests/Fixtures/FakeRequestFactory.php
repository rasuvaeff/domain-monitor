<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

final class FakeRequestFactory implements RequestFactoryInterface
{
    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new FakeRequest(method: $method, uri: (string) $uri);
    }
}
