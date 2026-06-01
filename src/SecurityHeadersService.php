<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
final readonly class SecurityHeadersService
{
    private const array REQUIRED_HEADERS = [
        'Strict-Transport-Security',
        'Content-Security-Policy',
        'X-Frame-Options',
        'X-Content-Type-Options',
    ];

    public function check(ResponseInterface $response): SecurityHeadersCheck
    {
        $presentHeaders = [];
        $missingHeaders = [];

        foreach (self::REQUIRED_HEADERS as $header) {
            if ($response->hasHeader(name: $header)) {
                $presentHeaders[] = $header;
            } else {
                $missingHeaders[] = $header;
            }
        }

        return new SecurityHeadersCheck(
            status: $missingHeaders === [] ? CheckStatus::OK : CheckStatus::WARNING,
            hasHsts: $response->hasHeader(name: 'Strict-Transport-Security'),
            hasContentSecurityPolicy: $response->hasHeader(name: 'Content-Security-Policy'),
            hasXFrameOptions: $response->hasHeader(name: 'X-Frame-Options'),
            hasXContentTypeOptions: $response->hasHeader(name: 'X-Content-Type-Options'),
            presentHeaders: $presentHeaders,
            missingHeaders: $missingHeaders,
        );
    }
}
