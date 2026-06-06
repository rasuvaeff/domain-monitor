<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\HostNormalizer;

#[CoversClass(HostNormalizer::class)]
final class HostNormalizerTest extends TestCase
{
    private HostNormalizer $normalizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->normalizer = new HostNormalizer();
    }

    #[Test]
    #[DataProvider('hostProvider')]
    public function normalizesHost(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalizeHost(hostOrUrl: $input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hostProvider(): iterable
    {
        yield 'plain host' => ['example.com', 'example.com'];
        yield 'subdomain' => ['sub.example.com', 'sub.example.com'];
        yield 'uppercase' => ['EXAMPLE.COM', 'example.com'];
        yield 'mixed case trailing dot' => ['Example.COM.', 'example.com'];
        yield 'trailing dot' => ['example.com.', 'example.com'];
        yield 'full url with scheme port path query fragment' => ['HTTPS://Example.COM:8443/path?x=1#frag', 'example.com'];
        yield 'scheme only host' => ['http://example.com', 'example.com'];
        yield 'leading slashes without scheme' => ['//example.com/path', 'example.com'];
        yield 'leading whitespace is trimmed' => ['  example.com  ', 'example.com'];
        yield 'leading tabs are trimmed' => ["\texample.com\t", 'example.com'];
    }

    #[Test]
    #[DataProvider('invalidHostProvider')]
    public function throwsOnInvalidHost(string $value, string $expectedMessage): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: $expectedMessage);

        $this->normalizer->normalizeHost(hostOrUrl: $value);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidHostProvider(): iterable
    {
        yield 'empty' => ['', 'Host must not be empty'];
        yield 'whitespace' => ['   ', 'Host must not be empty'];
        yield 'dots only' => ['...', 'Host must not be empty'];
        yield 'space in host' => ['exa mple.com', 'Invalid host "exa mple.com"'];
        yield 'underscore' => ['under_score.example', 'Invalid host "under_score.example"'];
        yield 'scheme without host' => ['http://', 'Invalid host "http://"'];
    }

    #[Test]
    public function normalizesIdnWhenIntlIsAvailable(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            $this->markTestSkipped(message: 'ext-intl is not available');
        }

        $this->assertSame('xn--e1aybc.xn--p1ai', $this->normalizer->normalizeHost(hostOrUrl: 'тест.рф'));
    }

    #[Test]
    public function normalizesIdnUppercaseWhenIntlIsAvailable(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            $this->markTestSkipped(message: 'ext-intl is not available');
        }

        $result = $this->normalizer->normalizeHost(hostOrUrl: 'ТЕСТ.РФ');

        $this->assertSame($result, \strtolower($result));
    }

    #[Test]
    public function normalizesIdnHostToExactLowercase(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            $this->markTestSkipped(message: 'ext-intl is not available');
        }

        $result = $this->normalizer->normalizeHost(hostOrUrl: 'Тест.рф');

        $this->assertSame(\strtolower($result), $result);
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function normalizesUrl(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalizeUrl(url: $input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'lowercases scheme and host, strips fragment, keeps port path query' => [
            'HTTPS://Example.com.:8443/path?x=1#fragment',
            'https://example.com:8443/path?x=1',
        ];
        yield 'adds default root path' => ['http://example.com', 'http://example.com/'];
        yield 'keeps explicit path case' => ['https://EXAMPLE.com/Path', 'https://example.com/Path'];
        yield 'user and password' => ['https://user:pass@example.com/p', 'https://user:pass@example.com/p'];
        yield 'user without password' => ['https://user@example.com/p', 'https://user@example.com/p'];
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function throwsOnInvalidUrl(string $value, string $expectedMessage): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: $expectedMessage);

        $this->normalizer->normalizeUrl(url: $value);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'empty' => ['', 'URL must not be empty'];
        yield 'whitespace' => ['   ', 'URL must not be empty'];
        yield 'no scheme' => ['/just/path', 'Invalid URL "/just/path"'];
        yield 'scheme without host' => ['http://', 'Invalid URL "http://"'];
        yield 'non-http scheme' => ['ftp://example.com', 'URL must use http or https scheme: "ftp://example.com"'];
    }
}
