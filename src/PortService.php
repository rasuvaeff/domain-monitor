<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class PortService
{
    private Closure $connector;

    public function __construct(?callable $connector = null)
    {
        $this->connector = $connector === null
            ? self::defaultConnector()
            : Closure::fromCallable(callback: $connector);
    }

    public function check(string $host, int $port, float $timeoutSeconds = 5.0): PortCheck
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(message: \sprintf('Invalid port %d', $port));
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException(message: 'Timeout must be greater than 0');
        }

        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $connector = $this->connector;

        /** @var array{success: bool, connectTime: float, error: ?string} $result */
        $result = $connector($normalizedHost, $port, $timeoutSeconds);

        return new PortCheck(
            status: $result['success'] ? CheckStatus::OK : CheckStatus::CRITICAL,
            host: $normalizedHost,
            port: $port,
            connectTime: $result['connectTime'],
            error: $result['error'],
        );
    }

    private static function defaultConnector(): Closure
    {
        return static function (string $host, int $port, float $timeoutSeconds): array {
            $errorMessage = null;
            \set_error_handler(
                callback: static function (int $severity, string $message) use (&$errorMessage): bool {
                    $errorMessage = $message;

                    return true;
                },
            );

            $startedAt = \microtime(as_float: true);

            try {
                $stream = \stream_socket_client(
                    address: \sprintf('tcp://%s:%d', $host, $port),
                    error_code: $errorCode,
                    error_message: $systemErrorMessage,
                    timeout: $timeoutSeconds,
                );
            } finally {
                \restore_error_handler();
            }

            $connectTime = \microtime(as_float: true) - $startedAt;

            if ($stream === false) {
                return [
                    'success' => false,
                    'connectTime' => $connectTime,
                    'error' => $errorMessage ?? $systemErrorMessage,
                ];
            }

            \fclose(stream: $stream);

            return [
                'success' => true,
                'connectTime' => $connectTime,
                'error' => null,
            ];
        };
    }
}
