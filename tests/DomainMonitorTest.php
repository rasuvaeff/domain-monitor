<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\DomainMonitor\ReportThresholds;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeWhois;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FlakyHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\StubDnsService;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\Retry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DomainMonitor::class)]
final class DomainMonitorTest
{
    public function returnsReportWithAllNullsWhenNoServicesConfigured(): void
    {
        $report = (new DomainMonitor())->check(host: 'example.com');

        Assert::same($report->host, 'example.com');
        Assert::null($report->probe);
        Assert::null($report->ssl);
        Assert::null($report->whois);
        Assert::null($report->dns);
        Assert::null($report->content);
        Assert::null($report->port);
        Assert::null($report->securityHeaders);
        Assert::null($report->robotsTxt);
        Assert::null($report->sitemap);
        Assert::same($report->getStatus(), CheckStatus::UNKNOWN);
    }

    public function normalizesHostBeforeRunningChecks(): void
    {
        $report = (new DomainMonitor())->check(host: 'https://EXAMPLE.com/path?query=1');

        Assert::same($report->host, 'example.com');
    }

    public function acceptsCustomServiceImplementationsViaInterfaces(): void
    {
        $monitor = new DomainMonitor(dns: new StubDnsService());

        $report = $monitor->check(host: 'example.com');

        Assert::same($report->dns?->a, ['9.9.9.9']);
    }

    public function throwsWhenSecurityHeadersConfiguredWithoutHttpProbe(): void
    {
        try {
            new DomainMonitor(securityHeaders: new SecurityHeadersService());
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('HttpProbeService');
        }
    }

    public function probeRunsAndReturnsStatusInReport(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 200);
        Assert::instanceOf($client->lastRequest, FakeRequest::class);
        Assert::same($client->lastRequest->getUriString(), 'https://example.com/');
    }

    public function reusesProbeResponseForSecurityHeaders(): void
    {
        $response = new FakeResponse(
            statusCode: 200,
            body: '',
            headers: [
                'Strict-Transport-Security' => ['max-age=31536000'],
                'Content-Security-Policy' => ["default-src 'self'"],
                'X-Frame-Options' => ['DENY'],
                'X-Content-Type-Options' => ['nosniff'],
            ],
        );
        $client = new RecordingHttpClient(response: $response);

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->securityHeaders);
        Assert::true($report->securityHeaders->hasHsts);
        Assert::true($report->securityHeaders->hasContentSecurityPolicy);
        Assert::true($report->securityHeaders->hasXFrameOptions);
        Assert::true($report->securityHeaders->hasXContentTypeOptions);
        Assert::same($report->securityHeaders->status, CheckStatus::OK);
    }

    public function reusesProbeResponseForContentCheck(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'hello world');
        $client = new RecordingHttpClient(response: $response);
        $probeService = new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory());
        $contentService = new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: new FakeResponse(statusCode: 500, body: 'wrong')),
            requestFactory: new FakeRequestFactory(),
        );

        $monitor = new DomainMonitor(
            httpProbe: $probeService,
            content: $contentService,
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(requiredText: 'hello'),
        );

        Assert::notNull($report->content);
        Assert::true($report->content->requiredTextFound);
        Assert::same($report->content->status, CheckStatus::OK);
    }

    public function contentMakesOwnRequestWhenProbeNotConfigured(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: 'ok'));
        $monitor = new DomainMonitor(
            content: new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->content);
        Assert::same($report->content->status, CheckStatus::OK);
        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getUriString(), 'https://example.com/');
    }

    public function probeFailureSetsStatusZeroAndOmitsSecurityHeaders(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'connection refused'));
        $logger = new RecordingLogger();

        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 0);
        Assert::true($report->probe->totalTime >= 0.0);
        Assert::true($report->probe->totalTime < 1.0);
        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
        Assert::null($report->securityHeaders);

        Assert::count($logger->records, 1);
        $probeLog = $logger->records[0];
        Assert::same($probeLog['level'], 'warning');
        Assert::same($probeLog['message'], 'HTTP probe failed');
        Assert::same($probeLog['context']['host'], 'example.com');
        Assert::same($probeLog['context']['check'], 'probe');
        Assert::same($probeLog['context']['error'], 'connection refused');
    }

    public function serviceExceptionIsCaughtAndOmittedFromReport(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'port closed')),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::null($report->port);
    }

    public function serviceExceptionIsLoggedWithCheckName(): void
    {
        $logger = new RecordingLogger();
        $monitor = new DomainMonitor(
            logger: $logger,
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'timeout')),
        );

        $monitor->check(host: 'example.com');

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'port check failed: timeout');
        Assert::same($logger->records[0]['context']['host'], 'example.com');
        Assert::same($logger->records[0]['context']['check'], 'port');
    }

    public function passesPortAndTimeoutOptionsToPortService(): void
    {
        $connectorArgs = null;
        $connector = static function (string $host, int $port, float $timeout) use (&$connectorArgs): array {
            $connectorArgs = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

            return ['success' => true, 'connectTime' => 0.01, 'error' => null];
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(port: 8443, timeoutSeconds: 15.0),
        );

        Assert::same(
            $connectorArgs,
            ['host' => 'example.com', 'port' => 8443, 'timeout' => 15.0],
        );
    }

    public function passesCustomResolverToDnsService(): void
    {
        $resolverHost = null;
        $resolver = static function (string $host, int $type) use (&$resolverHost): array|false {
            $resolverHost = $host;

            return [
                ['type' => 'A', 'ip' => '1.2.3.4'],
                ['type' => 'NS', 'target' => 'ns1.example.com'],
            ];
        };

        $monitor = new DomainMonitor(
            dns: new DnsService(resolver: $resolver),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::same($resolverHost, 'example.com');
        Assert::notNull($report->dns);
        Assert::same($report->dns->a, ['1.2.3.4']);
        Assert::same($report->dns->ns, ['ns1.example.com']);
    }

    public function returnsProperDomainHealthReportInstance(): void
    {
        $monitor = new DomainMonitor();

        $report = $monitor->check(host: 'example.com');

        Assert::instanceOf($report, DomainHealthReport::class);
    }

    public function retryRetriesTransientCheckFailureUntilSuccess(): void
    {
        $connectorCalls = 0;
        $connector = static function () use (&$connectorCalls): array {
            $connectorCalls++;

            if ($connectorCalls < 3) {
                throw new \RuntimeException(message: 'transient failure');
            }

            return ['success' => true, 'connectTime' => 0.01, 'error' => null];
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 3)),
        );

        Assert::same($connectorCalls, 3);
        Assert::notNull($report->port);
        Assert::same($report->port->status, CheckStatus::OK);
        Assert::false($report->hasErrors());
    }

    public function retryExhaustionIsRecordedAsCheckError(): void
    {
        $connectorCalls = 0;
        $connector = static function () use (&$connectorCalls): array {
            $connectorCalls++;

            throw new \RuntimeException(message: 'port closed');
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 2)),
        );

        Assert::same($connectorCalls, 2);
        Assert::null($report->port);
        Assert::true($report->hasErrors());

        $errors = $report->getErrors();

        Assert::count($errors, 1);
        Assert::same($errors[0]->check, CheckName::Port);
        Assert::string($errors[0]->message)->contains('Retry exhausted after 2 attempt(s)');
        Assert::string($errors[0]->message)->contains('port closed');
    }

    public function withoutRetryEachCheckRunsExactlyOnce(): void
    {
        $connectorCalls = 0;
        $connector = static function () use (&$connectorCalls): array {
            $connectorCalls++;

            throw new \RuntimeException(message: 'port closed');
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::same($connectorCalls, 1);
        Assert::true($report->hasErrors());
    }

    public function retryWrapsHttpProbe(): void
    {
        $client = new FlakyHttpClient(
            failures: 1,
            response: new FakeResponse(statusCode: 200),
        );

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 3)),
        );

        Assert::same($client->calls, 2);
        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 200);
        Assert::false($report->hasErrors());
    }

    public function probeRetryExhaustionSetsStatusZeroAndLogsWarning(): void
    {
        $client = new FlakyHttpClient(
            failures: 5,
            response: new FakeResponse(statusCode: 200),
        );
        $logger = new RecordingLogger();

        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 2)),
        );

        Assert::same($client->calls, 2);
        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 0);
        Assert::null($report->securityHeaders);
        Assert::false($report->hasErrors());

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'HTTP probe failed');
        Assert::string($logger->records[0]['context']['error'])->contains('Retry exhausted after 2 attempt(s)');
    }

    public function circuitBreakerAdmittedRunReturnsReport(): void
    {
        $client = new FlakyHttpClient(
            failures: 100,
            response: new FakeResponse(statusCode: 200),
        );
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(circuitBreaker: $this->breaker()),
        );

        Assert::same($client->calls, 1);
        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 0);
        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
    }

    public function circuitBreakerRejectionSkipsAllChecksAndRecordsError(): void
    {
        $client = new FlakyHttpClient(
            failures: 100,
            response: new FakeResponse(statusCode: 200),
        );
        $logger = new RecordingLogger();
        $breaker = $this->breaker();
        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );
        $thresholds = new ReportThresholds(sslWarnDays: 7);
        $options = new DomainMonitorOptions(
            thresholds: $thresholds,
            circuitBreaker: $breaker,
        );

        $first = $monitor->check(host: 'example.com', options: $options);
        $rejected = $monitor->check(host: 'example.com', options: $options);

        Assert::same($client->calls, 1);
        Assert::same($first->getStatus(), CheckStatus::CRITICAL);

        Assert::same($rejected->host, 'example.com');
        Assert::true($rejected->hasErrors());

        $errors = $rejected->getErrors();

        Assert::count($errors, 1);
        Assert::same($errors[0]->check, CheckName::Probe);
        Assert::string($errors[0]->message)->contains('Circuit "domain-monitor" is open');
        Assert::same($rejected->thresholds, $thresholds);

        Assert::count($logger->records, 2);
        Assert::same($logger->records[1]['message'], 'Domain check rejected by circuit breaker');
        Assert::same($logger->records[1]['context']['host'], 'example.com');
    }

    public function circuitBreakerStaysClosedWhenChecksSucceed(): void
    {
        $client = new FlakyHttpClient(
            failures: 0,
            response: new FakeResponse(statusCode: 200),
        );
        $breaker = $this->breaker();
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );
        $options = new DomainMonitorOptions(circuitBreaker: $breaker);

        $first = $monitor->check(host: 'example.com', options: $options);
        $second = $monitor->check(host: 'example.com', options: $options);

        Assert::same($client->calls, 2);
        Assert::same($first->getStatus(), CheckStatus::OK);
        Assert::same($second->getStatus(), CheckStatus::OK);
        Assert::false($second->hasErrors());
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            config: new BreakerConfig(
                name: 'domain-monitor',
                failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $exception): bool => true,
                classifyResult: static fn(mixed $result): Outcome => $result instanceof DomainHealthReport && $result->getStatus() === CheckStatus::CRITICAL
                    ? Outcome::Failure
                    : Outcome::Success,
            ),
            storage: new InMemoryStorage(),
            clock: new SystemClock(),
        );
    }

    public function failedCheckIsRecordedAsCheckError(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'port closed')),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::null($report->port);
        Assert::true($report->hasErrors());

        $errors = $report->getErrors();

        Assert::count($errors, 1);
        Assert::same($errors[0]->check, CheckName::Port);
        Assert::string($errors[0]->message)->contains('port closed');

        $portCheck = $report->getCheck(name: CheckName::Port);

        Assert::notNull($portCheck);
        Assert::same($portCheck->status, CheckStatus::UNKNOWN);
    }

    public function propagatesThresholdsFromOptionsToReport(): void
    {
        $thresholds = new ReportThresholds(sslWarnDays: 14);

        $report = (new DomainMonitor())->check(
            host: 'example.com',
            options: new DomainMonitorOptions(thresholds: $thresholds),
        );

        Assert::same($report->thresholds, $thresholds);
    }

    public function createWiresEveryServiceFromHttpAndWhois(): void
    {
        $monitor = DomainMonitor::create(
            httpClient: new RecordingHttpClient(response: new FakeResponse(statusCode: 200)),
            requestFactory: new FakeRequestFactory(),
            whois: new FakeWhois(handler: static fn(string $domain) => null),
        );

        Assert::notNull($monitor->httpProbe);
        Assert::notNull($monitor->ssl);
        Assert::notNull($monitor->whois);
        Assert::notNull($monitor->dns);
        Assert::notNull($monitor->port);
        Assert::notNull($monitor->securityHeaders);
        Assert::notNull($monitor->robotsTxt);
        Assert::notNull($monitor->sitemap);
        Assert::notNull($monitor->content);
    }

    public function createWithoutWhoisDisablesWhoisCheck(): void
    {
        $monitor = DomainMonitor::create(
            httpClient: new RecordingHttpClient(response: new FakeResponse(statusCode: 200)),
            requestFactory: new FakeRequestFactory(),
        );

        Assert::null($monitor->whois);
    }

    public function runsAllControllableServicesAndAssemblesReport(): void
    {
        $probeResponse = new FakeResponse(
            statusCode: 200,
            body: 'healthy content',
            headers: [
                'Strict-Transport-Security' => ['max-age=31536000'],
                'Content-Security-Policy' => ["default-src 'self'"],
                'X-Frame-Options' => ['DENY'],
                'X-Content-Type-Options' => ['nosniff'],
            ],
        );
        $probeClient = new RecordingHttpClient(response: $probeResponse);
        $robotsResponse = new FakeResponse(statusCode: 200, body: "Sitemap: https://example.com/sitemap.xml\n");
        $robotsClient = new RecordingHttpClient(response: $robotsResponse);
        $sitemapResponse = new FakeResponse(statusCode: 200, body: '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/</loc></url></urlset>');
        $sitemapClient = new RecordingHttpClient(response: $sitemapResponse);

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $probeClient, requestFactory: new FakeRequestFactory()),
            dns: new DnsService(resolver: static fn(): array|false => [['type' => 'A', 'ip' => '1.2.3.4']]),
            port: new PortService(connector: static fn(): array => ['success' => true, 'connectTime' => 0.02, 'error' => null]),
            securityHeaders: new SecurityHeadersService(),
            robotsTxt: new RobotsTxtService(httpClient: $robotsClient, requestFactory: new FakeRequestFactory()),
            sitemap: new SitemapService(httpClient: $sitemapClient, requestFactory: new FakeRequestFactory()),
            content: new HttpContentCheckService(httpClient: $probeClient, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::same($report->probe?->status, 200);
        Assert::true($report->securityHeaders?->hasHsts);
        Assert::same($report->content?->status, CheckStatus::OK);
        Assert::true($report->robotsTxt?->exists);
        Assert::same($report->robotsTxt?->sitemaps, ['https://example.com/sitemap.xml']);
        Assert::true($report->sitemap?->exists);
        Assert::same($report->sitemap?->urlCount, 1);
        Assert::same($report->dns?->a, ['1.2.3.4']);
        Assert::same($report->port?->status, CheckStatus::OK);
        Assert::same($report->port?->connectTime, 0.02);
        Assert::same($report->getStatus(), CheckStatus::OK);
    }
}
