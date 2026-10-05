<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Iodev\Whois\Whois;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Outcome;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\DomainMonitor\CheckName;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\DnsServiceInterface;
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
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(DomainMonitor::class)]
final class DomainMonitorTest
{
    private Captor $requests;

    private Captor $warningMessages;

    private Captor $warningContexts;

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
        $monitor = new DomainMonitor(dns: $this->stubDns());

        $report = $monitor->check(host: 'example.com');

        Assert::same($report->dns?->unwrap()->a, ['9.9.9.9']);
    }

    public function exhaustedBudgetSkipsChecksAsErrSlots(): void
    {
        $connectorCalls = 0;
        $connector = static function () use (&$connectorCalls): array {
            $connectorCalls++;

            return ['success' => true, 'connectTime' => 0.01, 'error' => null];
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(maxDuration: Duration::zero()),
        );

        Assert::same($connectorCalls, 0);
        Assert::true($report->port?->isErr() ?? false);

        $errors = $report->getErrors();

        Assert::count($errors, 1);
        Assert::same($errors[0]->check, CheckName::Port);
        Assert::same($errors[0]->message, 'Time budget exceeded');
        Assert::same($report->getStatus(), CheckStatus::UNKNOWN);
    }

    public function generousBudgetRunsAllChecks(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => ['success' => true, 'connectTime' => 0.01, 'error' => null]),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(maxDuration: Duration::seconds(60)),
        );

        Assert::true($report->port?->isOk() ?? false);
        Assert::same($report->port->unwrap()->status, CheckStatus::OK);
        Assert::false($report->hasErrors());
    }

    public function checkManyReturnsReportsKeyedByNormalizedHost(): void
    {
        $monitor = new DomainMonitor(dns: $this->stubDns());

        $reports = $monitor->checkMany(
            hosts: ['https://EXAMPLE.com/path', 'example.org'],
            options: new DomainMonitorOptions(maxDuration: Duration::seconds(60)),
        );

        Assert::count($reports, 2);
        Assert::same(\array_keys($reports), ['example.com', 'example.org']);
        Assert::instanceOf($reports['example.com'], DomainHealthReport::class);
        Assert::same($reports['example.org']->dns?->unwrap()->a, ['9.9.9.9']);
        Assert::same($reports['example.com']->host, 'example.com');
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
        $client = $this->client(new FakeResponse(statusCode: 200));
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->probe);
        Assert::same($report->probe->unwrap()->status, 200);
        Assert::same($this->requests->last()->getUriString(), 'https://example.com/');
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
        $client = $this->client($response);

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->securityHeaders);
        Assert::true($report->securityHeaders->unwrap()->hasHsts);
        Assert::true($report->securityHeaders->unwrap()->hasContentSecurityPolicy);
        Assert::true($report->securityHeaders->unwrap()->hasXFrameOptions);
        Assert::true($report->securityHeaders->unwrap()->hasXContentTypeOptions);
        Assert::same($report->securityHeaders->unwrap()->status, CheckStatus::OK);
    }

    public function reusesProbeResponseForContentCheck(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'hello world');
        $client = $this->client($response);
        $probeService = new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory());
        $contentService = new HttpContentCheckService(
            httpClient: $this->client(new FakeResponse(statusCode: 500, body: 'wrong')),
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
        Assert::true($report->content->unwrap()->requiredTextFound);
        Assert::same($report->content->unwrap()->status, CheckStatus::OK);
    }

    public function contentMakesOwnRequestWhenProbeNotConfigured(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: 'ok'));
        $monitor = new DomainMonitor(
            content: new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->content);
        Assert::same($report->content->unwrap()->status, CheckStatus::OK);
        Assert::same($this->requests->last()->getUriString(), 'https://example.com/');
    }

    public function probeFailureSetsStatusZeroAndOmitsSecurityHeaders(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'connection refused'));
        $logger = $this->logger();

        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->probe);
        Assert::same($report->probe->unwrap()->status, 0);
        Assert::true($report->probe->unwrap()->totalTime >= 0.0);
        Assert::true($report->probe->unwrap()->totalTime < 1.0);
        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
        Assert::null($report->securityHeaders);

        verify(fn() => $logger->warning('HTTP probe failed', [
            'host' => 'example.com',
            'check' => 'probe',
            'error' => 'connection refused',
        ]));
    }

    public function serviceExceptionBecomesErrSlot(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'port closed')),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->port);
        Assert::true($report->port->isErr());
        Assert::false($report->port->isOk());
    }

    public function serviceExceptionIsLoggedWithCheckName(): void
    {
        $logger = $this->logger();
        $monitor = new DomainMonitor(
            logger: $logger,
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'timeout')),
        );

        $monitor->check(host: 'example.com');

        verify(fn() => $logger->warning('port check failed: timeout', [
            'host' => 'example.com',
            'check' => 'port',
        ]));
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
        Assert::same($report->dns->unwrap()->a, ['1.2.3.4']);
        Assert::same($report->dns->unwrap()->ns, ['ns1.example.com']);
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
        Assert::same($report->port->unwrap()->status, CheckStatus::OK);
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
        Assert::true($report->port?->isErr() ?? false);
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
        $client = $this->flakyClient(failures: 1, response: new FakeResponse(statusCode: 200));

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 3)),
        );

        verify(fn() => $client->sendRequest(Arg::any()), times: 2);
        Assert::notNull($report->probe);
        Assert::same($report->probe->unwrap()->status, 200);
        Assert::false($report->hasErrors());
    }

    public function probeRetryExhaustionSetsStatusZeroAndLogsWarning(): void
    {
        $client = $this->flakyClient(failures: 5, response: new FakeResponse(statusCode: 200));
        $logger = $this->logger();

        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(retry: Retry::immediate(maxAttempts: 2)),
        );

        verify(fn() => $client->sendRequest(Arg::any()), times: 2);
        Assert::notNull($report->probe);
        Assert::same($report->probe->unwrap()->status, 0);
        Assert::null($report->securityHeaders);
        Assert::false($report->hasErrors());

        verify(fn() => $logger->warning('HTTP probe failed', Arg::any()));
        Assert::string($this->warningContexts->last()['error'])->contains('Retry exhausted after 2 attempt(s)');
    }

    public function circuitBreakerAdmittedRunReturnsReport(): void
    {
        $client = $this->flakyClient(failures: 100, response: new FakeResponse(statusCode: 200));
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(circuitBreaker: $this->breaker()),
        );

        verify(fn() => $client->sendRequest(Arg::any()), times: 1);
        Assert::notNull($report->probe);
        Assert::same($report->probe->unwrap()->status, 0);
        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
    }

    public function circuitBreakerRejectionSkipsAllChecksAndRecordsError(): void
    {
        $client = $this->flakyClient(failures: 100, response: new FakeResponse(statusCode: 200));
        $logger = $this->logger();
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

        verify(fn() => $client->sendRequest(Arg::any()), times: 1);
        Assert::same($first->getStatus(), CheckStatus::CRITICAL);

        Assert::same($rejected->host, 'example.com');
        Assert::true($rejected->hasErrors());

        $errors = $rejected->getErrors();

        Assert::count($errors, 1);
        Assert::same($errors[0]->check, CheckName::Probe);
        Assert::string($errors[0]->message)->contains('Circuit "domain-monitor" is open');
        Assert::same($rejected->thresholds, $thresholds);

        Assert::same($this->warningMessages->all(), [
            'HTTP probe failed',
            'Domain check rejected by circuit breaker',
        ]);
        Assert::same($this->warningContexts->all()[1]['host'], 'example.com');
    }

    public function circuitBreakerStaysClosedWhenChecksSucceed(): void
    {
        $client = $this->flakyClient(failures: 0, response: new FakeResponse(statusCode: 200));
        $breaker = $this->breaker();
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );
        $options = new DomainMonitorOptions(circuitBreaker: $breaker);

        $first = $monitor->check(host: 'example.com', options: $options);
        $second = $monitor->check(host: 'example.com', options: $options);

        verify(fn() => $client->sendRequest(Arg::any()), times: 2);
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

        Assert::true($report->port?->isErr() ?? false);
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
        $whois = Understudy::for(Whois::class);
        when(fn() => $whois->loadDomainInfo(Arg::any()))->returns(null);

        $monitor = DomainMonitor::create(
            httpClient: $this->client(new FakeResponse(statusCode: 200)),
            requestFactory: new FakeRequestFactory(),
            whois: $whois,
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
            httpClient: $this->client(new FakeResponse(statusCode: 200)),
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
        $probeClient = $this->client($probeResponse);
        $robotsClient = $this->client(new FakeResponse(statusCode: 200, body: "Sitemap: https://example.com/sitemap.xml\n"));
        $sitemapClient = $this->client(new FakeResponse(statusCode: 200, body: '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/</loc></url></urlset>'));

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

        Assert::same($report->probe?->unwrap()?->status, 200);
        Assert::true($report->securityHeaders?->unwrap()?->hasHsts);
        Assert::same($report->content?->unwrap()?->status, CheckStatus::OK);
        Assert::true($report->robotsTxt?->unwrap()?->exists);
        Assert::same($report->robotsTxt?->unwrap()?->sitemaps, ['https://example.com/sitemap.xml']);
        Assert::true($report->sitemap?->unwrap()?->exists);
        Assert::same($report->sitemap?->unwrap()?->urlCount, 1);
        Assert::same($report->dns?->unwrap()?->a, ['1.2.3.4']);
        Assert::same($report->port?->unwrap()?->status, CheckStatus::OK);
        Assert::same($report->port?->unwrap()?->connectTime, 0.02);
        Assert::same($report->getStatus(), CheckStatus::OK);
    }

    private function client(ResponseInterface $response): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        $this->requests = Arg::captor(FakeRequest::class);

        when(fn() => $client->sendRequest($this->requests->capture()))->returns($response);

        return $client;
    }

    private function failingClient(ClientExceptionInterface $exception): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);

        when(fn() => $client->sendRequest(Arg::any()))->throws($exception);

        return $client;
    }

    /**
     * A client that throws on its first `$failures` calls and answers with
     * `$response` afterwards; with `$failures` above what a test can spend,
     * every call throws.
     */
    private function flakyClient(int $failures, ResponseInterface $response): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);

        if ($failures === 0) {
            when(fn() => $client->sendRequest(Arg::any()))->returns($response);
        } elseif ($failures === 1) {
            when(fn() => $client->sendRequest(Arg::any()))
                ->throws(new ClientExceptionStub(message: 'transient failure #1'))
                ->then()->returns($response);
        } else {
            when(fn() => $client->sendRequest(Arg::any()))
                ->throws(new ClientExceptionStub(message: 'transient failure'));
        }

        return $client;
    }

    private function logger(): LoggerInterface
    {
        $logger = Understudy::for(LoggerInterface::class);
        $this->warningMessages = Arg::captor();
        $this->warningContexts = Arg::captor();

        when(fn() => $logger->warning($this->warningMessages->capture(), $this->warningContexts->capture()));

        return $logger;
    }

    private function stubDns(): DnsServiceInterface
    {
        $dns = Understudy::for(DnsServiceInterface::class);

        when(fn() => $dns->check(Arg::any()))->returns(new DnsRecords(a: ['9.9.9.9']));

        return $dns;
    }
}
