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
final readonly class RobotsTxtService
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(string $baseUrl, ?HttpProbeOptions $options = null): RobotsTxtCheck
    {
        $normalizedBaseUrl = (new HostNormalizer())->normalizeUrl(url: $baseUrl);
        $probeOptions = $options ?? new HttpProbeOptions();
        $robotsUrl = $this->buildRobotsUrl(baseUrl: $normalizedBaseUrl);
        $request = $this->requestFactory->createRequest(
            method: $probeOptions->method,
            uri: $robotsUrl,
        );

        foreach ($probeOptions->headers as $name => $value) {
            $request = $request->withHeader(name: $name, value: $value);
        }

        if (!$request->hasHeader(name: 'User-Agent')) {
            $request = $request->withHeader(name: 'User-Agent', value: $probeOptions->userAgent);
        }

        try {
            $response = $this->httpClient->sendRequest(request: $request);
            $statusCode = $response->getStatusCode();

            return new RobotsTxtCheck(
                status: $statusCode === 200 ? CheckStatus::OK : CheckStatus::WARNING,
                httpStatus: $statusCode,
                exists: $statusCode === 200,
                sitemaps: $statusCode === 200
                    ? $this->extractSitemaps(content: $response->getBody()->__toString())
                    : [],
            );
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error(
                message: $exception->getMessage(),
                context: ['url' => $robotsUrl],
            );

            return new RobotsTxtCheck(
                status: CheckStatus::UNKNOWN,
                httpStatus: 0,
                exists: false,
                sitemaps: [],
            );
        }
    }

    private function buildRobotsUrl(string $baseUrl): string
    {
        $parts = \parse_url(url: $baseUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $baseUrl . '/robots.txt';
        }

        $url = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        return $url . '/robots.txt';
    }

    /**
     * @return string[]
     */
    private function extractSitemaps(string $content): array
    {
        $sitemaps = [];
        $lines = \preg_split(pattern: '/\R/', subject: $content) ?: [];

        foreach ($lines as $line) {
            if (\preg_match(pattern: '/^\s*Sitemap:\s*(\S.*?)\s*$/iu', subject: $line, matches: $matches) === 1) {
                $sitemaps[] = $matches[1];
            }
        }

        return $sitemaps;
    }
}
