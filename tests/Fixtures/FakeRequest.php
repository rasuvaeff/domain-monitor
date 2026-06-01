<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class FakeRequest implements RequestInterface
{
    /**
     * @param array<string, string[]> $headers
     */
    public function __construct(
        private string $method = 'GET',
        private string $uri = '',
        private array $headers = [],
    ) {}

    #[\Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[\Override]
    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = $method;

        return $clone;
    }

    public function getUriString(): string
    {
        return $this->uri;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower(string: $name)]);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->headers[\strtolower(string: $name)] ?? [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return \implode(separator: ', ', array: $this->getHeader(name: $name));
    }

    #[\Override]
    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers[\strtolower(string: $name)] = \is_array($value) ? \array_values($value) : [$value];

        return $clone;
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $existing = $clone->headers[\strtolower(string: $name)] ?? [];
        $added = \is_array($value) ? \array_values($value) : [$value];
        $clone->headers[\strtolower(string: $name)] = [...$existing, ...$added];

        return $clone;
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[\strtolower(string: $name)]);

        return $clone;
    }

    /**
     * @return array<string, string[]>
     */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return $this;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return new StringStream(content: '');
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return $this;
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->uri === '' ? '/' : $this->uri;
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        throw new \RuntimeException(message: 'Not implemented');
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this;
    }
}
