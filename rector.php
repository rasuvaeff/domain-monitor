<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    // Rewrites `$x !== null` into `$x instanceof Some\Long\ClassName` on a
    // property whose declared type already says which class it is.
    // DomainHealthReport is a dozen optional check results in a row: the
    // rewrite spells out a fully qualified name on every one of them, adds
    // nothing the type declaration did not already state, and reads worse at
    // the only place it appears.
    ->withSkip([
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
