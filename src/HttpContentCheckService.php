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
final readonly class HttpContentCheckService
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(
        string $url,
        int $expectedStatus = 200,
        ?string $requiredText = null,
        ?string $forbiddenText = null,
        ?HttpProbeOptions $options = null,
    ): HttpContentCheck {
        if ($expectedStatus < 100 || $expectedStatus > 599) {
            throw new \InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $expectedStatus));
        }

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

        try {
            $response = $this->httpClient->sendRequest(request: $request);
            $body = $response->getBody()->__toString();
            $requiredTextFound = $requiredText === null || \str_contains(haystack: $body, needle: $requiredText);
            $forbiddenTextFound = $forbiddenText !== null && \str_contains(haystack: $body, needle: $forbiddenText);
            $status = $response->getStatusCode() === $expectedStatus && $requiredTextFound && !$forbiddenTextFound
                ? CheckStatus::OK
                : CheckStatus::CRITICAL;

            return new HttpContentCheck(
                status: $status,
                httpStatus: $response->getStatusCode(),
                finalUrl: null,
                requiredTextFound: $requiredTextFound,
                forbiddenTextFound: $forbiddenTextFound,
            );
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error(
                message: $exception->getMessage(),
                context: ['url' => $normalizedUrl],
            );

            return new HttpContentCheck(
                status: CheckStatus::CRITICAL,
                httpStatus: 0,
                finalUrl: null,
                requiredTextFound: false,
                forbiddenTextFound: false,
            );
        }
    }
}
