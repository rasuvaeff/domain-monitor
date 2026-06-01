<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$vendorDir = \dirname(__DIR__, 1) . '/vendor';

\set_error_handler(
    callback: static function (int $severity, string $message, string $file, int $line) use ($vendorDir): bool {
        unset($message, $line);

        if (($severity & \E_DEPRECATED) !== 0 && \str_starts_with(haystack: $file, needle: $vendorDir)) {
            return true;
        }

        return false;
    },
    error_levels: \E_DEPRECATED,
);
