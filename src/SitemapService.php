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
final readonly class SitemapService
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function check(string $sitemapUrl, ?HttpProbeOptions $options = null): SitemapCheck
    {
        $normalizedUrl = (new HostNormalizer())->normalizeUrl(url: $sitemapUrl);
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
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                return new SitemapCheck(
                    status: CheckStatus::WARNING,
                    httpStatus: $statusCode,
                    exists: false,
                    urlCount: 0,
                );
            }

            $body = $response->getBody()->__toString();
            $parsedCount = $this->countUrls(content: $body);

            if ($parsedCount === null) {
                return new SitemapCheck(
                    status: CheckStatus::WARNING,
                    httpStatus: $statusCode,
                    exists: true,
                    urlCount: 0,
                );
            }

            return new SitemapCheck(
                status: CheckStatus::OK,
                httpStatus: $statusCode,
                exists: true,
                urlCount: $parsedCount,
            );
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error(
                message: $exception->getMessage(),
                context: ['url' => $normalizedUrl],
            );

            return new SitemapCheck(
                status: CheckStatus::UNKNOWN,
                httpStatus: 0,
                exists: false,
                urlCount: 0,
            );
        }
    }

    private function countUrls(string $content): ?int
    {
        $previousUseInternalErrors = \libxml_use_internal_errors(use_errors: true);

        try {
            $xml = \simplexml_load_string(data: $content, class_name: \SimpleXMLElement::class, options: \LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors(use_errors: $previousUseInternalErrors);
        }

        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $namespaces = $xml->getDocNamespaces(recursive: true);

        if (isset($namespaces['']) && \is_string($namespaces[''])) {
            $xml->registerXPathNamespace(prefix: 'sm', namespace: $namespaces['']);
            /** @var list<\SimpleXMLElement>|false $namespacedMatches */
            $namespacedMatches = $xml->xpath('//sm:url');

            return \is_array($namespacedMatches) ? \count($namespacedMatches) : 0;
        }

        /** @var list<\SimpleXMLElement>|false $matches */
        $matches = $xml->xpath('//url');

        return \is_array($matches) ? \count($matches) : 0;
    }
}
