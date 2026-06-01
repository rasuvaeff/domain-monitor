<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @api
 */
final readonly class HttpProbeService
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(string $url, ?HttpProbeOptions $options = null): ProbeResult
    {
        $normalizedUrl = (new HostNormalizer())->normalizeUrl(url: $url);
        $probeOptions = $options ?? new HttpProbeOptions();
        $request = $this->requestFactory->createRequest(
            method: $probeOptions->method,
            uri: $normalizedUrl,
        );

        foreach ($probeOptions->headers as $name => $value) {
            $request = $request->withHeader(name: $name, value: $value);
        }

        if (!$request->hasHeader(name: 'User-Agent')) {
            $request = $request->withHeader(name: 'User-Agent', value: $probeOptions->userAgent);
        }

        $startedAt = \microtime(as_float: true);

        try {
            $response = $this->httpClient->sendRequest(request: $request);

            return new ProbeResult(
                status: $response->getStatusCode(),
                totalTime: \microtime(as_float: true) - $startedAt,
            );
        } catch (ClientExceptionInterface $exception) {
            $measuredSeconds = \microtime(as_float: true) - $startedAt;

            $this->logger->error(
                message: $exception->getMessage(),
                context: ['url' => $normalizedUrl],
            );

            return new ProbeResult(
                status: 0,
                totalTime: $measuredSeconds,
            );
        }
    }
}
