<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @api
 */
final readonly class HttpContentCheck implements JsonSerializable
{
    public function __construct(
        public CheckStatus $status,
        public int $httpStatus,
        public ?string $finalUrl,
        public bool $requiredTextFound,
        public bool $forbiddenTextFound,
    ) {
        if ($httpStatus !== 0 && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException(message: \sprintf('Invalid HTTP status %d', $httpStatus));
        }
    }

    /**
     * @return array{status: string, httpStatus: int, finalUrl: string|null, requiredTextFound: bool, forbiddenTextFound: bool}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'httpStatus' => $this->httpStatus,
            'finalUrl' => $this->finalUrl,
            'requiredTextFound' => $this->requiredTextFound,
            'forbiddenTextFound' => $this->forbiddenTextFound,
        ];
    }
}
