<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Retry\Retry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DomainMonitorOptions::class)]
final class DomainMonitorOptionsTest
{
    public function usesSensibleDefaults(): void
    {
        $options = new DomainMonitorOptions();

        Assert::same($options->port, 443);
        Assert::same($options->timeoutSeconds, 10.0);
        Assert::same($options->userAgent, 'rasuvaeff/domain-monitor');
        Assert::same($options->httpMethod, 'GET');
        Assert::null($options->expectedOrg);
        Assert::same($options->expectedStatus, 200);
        Assert::null($options->requiredText);
        Assert::null($options->forbiddenText);
        Assert::null($options->retry);
        Assert::null($options->circuitBreaker);
        Assert::null($options->timeout);
        Assert::null($options->maxDuration);
    }

    public function withTimeoutResolvesSecondsFromDuration(): void
    {
        $options = (new DomainMonitorOptions(timeoutSeconds: 30.0))
            ->withTimeout(Duration::seconds(2));

        Assert::same($options->timeoutSeconds, 2.0);
        Assert::notNull($options->timeout);
    }

    public function withTimeoutAcceptsSubSecondValues(): void
    {
        $options = (new DomainMonitorOptions())->withTimeout(Duration::millis(500));

        Assert::same($options->timeoutSeconds, 0.5);
    }

    public function withTimeoutPreservesSiblingOptions(): void
    {
        $options = (new DomainMonitorOptions(
            port: 8443,
            userAgent: 'monitor/2.0',
            httpMethod: 'HEAD',
            requiredText: 'healthy',
        ))->withTimeout(Duration::seconds(15));

        Assert::same($options->port, 8443);
        Assert::same($options->userAgent, 'monitor/2.0');
        Assert::same($options->httpMethod, 'HEAD');
        Assert::same($options->requiredText, 'healthy');
        Assert::same($options->timeoutSeconds, 15.0);
    }

    public function zeroTimeoutDurationThrows(): void
    {
        try {
            (new DomainMonitorOptions())->withTimeout(Duration::zero());
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Timeout must be greater than 0');
        }
    }

    public function uppercasesHttpMethod(): void
    {
        $options = new DomainMonitorOptions(httpMethod: 'head');

        Assert::same($options->httpMethod, 'HEAD');
    }

    public function preservesCustomValues(): void
    {
        $options = new DomainMonitorOptions(
            port: 8443,
            timeoutSeconds: 30.0,
            userAgent: 'monitor/2.0',
            httpMethod: 'POST',
            expectedOrg: 'Example Inc.',
            expectedStatus: 204,
            requiredText: 'healthy',
            forbiddenText: 'error',
            retry: Retry::immediate(maxAttempts: 5),
        );

        Assert::same($options->port, 8443);
        Assert::same($options->timeoutSeconds, 30.0);
        Assert::same($options->userAgent, 'monitor/2.0');
        Assert::same($options->httpMethod, 'POST');
        Assert::same($options->expectedOrg, 'Example Inc.');
        Assert::same($options->expectedStatus, 204);
        Assert::same($options->requiredText, 'healthy');
        Assert::same($options->forbiddenText, 'error');
        Assert::notNull($options->retry);
    }

    #[DataProvider('invalidPortProvider')]
    public function throwsOnInvalidPort(int $port): void
    {
        try {
            new DomainMonitorOptions(port: $port);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(\sprintf('Invalid port %d', $port));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too high' => [65536];
    }

    public function throwsOnZeroOrNegativeTimeout(): void
    {
        try {
            new DomainMonitorOptions(timeoutSeconds: 0.0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Timeout must be greater than 0');
        }
    }

    public function throwsOnEmptyUserAgent(): void
    {
        try {
            new DomainMonitorOptions(userAgent: '');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('User-Agent must not be empty');
        }
    }

    public function throwsOnInvalidHttpMethod(): void
    {
        try {
            new DomainMonitorOptions(httpMethod: 'GET POST');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP method "GET POST"');
        }
    }

    public function throwsOnHttpMethodWithTrailingNewline(): void
    {
        try {
            new DomainMonitorOptions(httpMethod: "GET\n");
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP method');
        }
    }

    #[DataProvider('invalidExpectedStatusProvider')]
    public function throwsOnInvalidExpectedStatus(int $status): void
    {
        try {
            new DomainMonitorOptions(expectedStatus: $status);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(\sprintf('Invalid HTTP status %d', $status));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidExpectedStatusProvider(): iterable
    {
        yield 'below range' => [99];
        yield 'above range' => [600];
    }

    public function throwsOnBlankExpectedOrg(): void
    {
        try {
            new DomainMonitorOptions(expectedOrg: '  ');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Expected organization must not be empty');
        }
    }

    public function acceptsBoundaryPorts(): void
    {
        $min = new DomainMonitorOptions(port: 1);
        $max = new DomainMonitorOptions(port: 65535);

        Assert::same($min->port, 1);
        Assert::same($max->port, 65535);
    }

    public function acceptsBoundaryExpectedStatus(): void
    {
        $min = new DomainMonitorOptions(expectedStatus: 100);
        $max = new DomainMonitorOptions(expectedStatus: 599);

        Assert::same($min->expectedStatus, 100);
        Assert::same($max->expectedStatus, 599);
    }
}
