<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class FakeResponse implements ResponseInterface
{
    private StreamInterface $body;

    /**
     * @param array<string, string[]> $headers
     */
    public function __construct(
        private int $statusCode = 200,
        string $body = '',
        private array $headers = [],
    ) {
        $this->body = new StringStream(content: $body);
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;

        return $clone;
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return '';
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
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

    /**
     * @return array<string, string[]>
     */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
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
        return $this;
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return $this;
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
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
}
