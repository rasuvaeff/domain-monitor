<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FlakyHttpClient implements ClientInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly int $failures,
        private readonly ResponseInterface $response,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;

        if ($this->calls <= $this->failures) {
            throw new ClientExceptionStub(message: \sprintf('transient failure #%d', $this->calls));
        }

        return $this->response;
    }
}
