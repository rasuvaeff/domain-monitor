<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(
        private readonly ?ResponseInterface $response = null,
        private readonly ?ClientExceptionInterface $exception = null,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->response === null) {
            throw new ClientExceptionStub(message: 'No response configured');
        }

        return $this->response;
    }
}
