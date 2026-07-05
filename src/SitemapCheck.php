<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @api
 */
final readonly class SitemapCheck implements JsonSerializable
{
    public function __construct(
        public CheckStatus $status,
        public int $httpStatus,
        public bool $exists,
        public int $urlCount,
    ) {
        if ($httpStatus !== 0 && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $httpStatus));
        }

        if ($urlCount < 0) {
            throw new InvalidArgumentException(message: 'URL count must be greater than or equal to 0');
        }
    }

    /**
     * @return array{status: string, httpStatus: int, exists: bool, urlCount: int}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'httpStatus' => $this->httpStatus,
            'exists' => $this->exists,
            'urlCount' => $this->urlCount,
        ];
    }
}
