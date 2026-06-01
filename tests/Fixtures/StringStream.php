<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class StringStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private string $content,
    ) {}

    public function __toString(): string
    {
        return $this->content;
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return \strlen(string: $this->content);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= \strlen(string: $this->content);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $length = \strlen(string: $this->content);

        $this->position = match ($whence) {
            \SEEK_CUR => $this->position + $offset,
            \SEEK_END => $length + $offset,
            default => $offset,
        };
    }

    #[\Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new RuntimeException(message: 'Stream is not writable');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = \substr(string: $this->content, offset: $this->position, length: $length);
        $this->position += \strlen(string: $chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $chunk = \substr(string: $this->content, offset: $this->position);
        $this->position = \strlen(string: $this->content);

        return $chunk;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return [];
        }

        return null;
    }
}
