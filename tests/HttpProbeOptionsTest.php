<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HttpProbeOptions::class)]
final class HttpProbeOptionsTest
{
    public function normalizesDefaultsAndMethod(): void
    {
        $options = new HttpProbeOptions(method: 'head');

        Assert::same($options->method, 'HEAD');
        Assert::same($options->headers, []);
        Assert::same($options->timeoutSeconds, 5.0);
        Assert::same($options->userAgent, 'rasuvaeff/domain-monitor');
        Assert::null($options->timeout);
    }

    public function withTimeoutResolvesSecondsFromDuration(): void
    {
        $options = (new HttpProbeOptions(timeoutSeconds: 30.0))
            ->withTimeout(Duration::seconds(2));

        Assert::same($options->timeoutSeconds, 2.0);
        Assert::notNull($options->timeout);
    }

    public function zeroTimeoutDurationThrows(): void
    {
        try {
            (new HttpProbeOptions())->withTimeout(Duration::zero());
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Timeout must be greater than 0');
        }
    }

    public function preservesCustomValues(): void
    {
        $options = new HttpProbeOptions(
            method: 'POST',
            headers: ['Accept' => 'application/json'],
            timeoutSeconds: 12.5,
            userAgent: 'custom/2.0',
        );

        Assert::same($options->method, 'POST');
        Assert::same($options->headers, ['Accept' => 'application/json']);
        Assert::same($options->timeoutSeconds, 12.5);
        Assert::same($options->userAgent, 'custom/2.0');
    }

    public function throwsOnEmptyHeaderName(): void
    {
        try {
            new HttpProbeOptions(headers: ['' => 'value']);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Header names must be non-empty strings');
        }
    }

    public function throwsOnEmptyUserAgent(): void
    {
        try {
            new HttpProbeOptions(userAgent: '');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('User-Agent must not be empty');
        }
    }

    public function throwsOnInvalidMethod(): void
    {
        try {
            new HttpProbeOptions(method: 'GET1');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP method "GET1"');
        }
    }

    public function throwsOnMethodWithTrailingNewline(): void
    {
        try {
            new HttpProbeOptions(method: "GET\n");
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP method');
        }
    }

    public function throwsOnInvalidTimeout(): void
    {
        try {
            new HttpProbeOptions(timeoutSeconds: 0.0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Timeout must be greater than 0');
        }
    }
}
