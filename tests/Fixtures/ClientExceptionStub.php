<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class ClientExceptionStub extends RuntimeException implements ClientExceptionInterface {}
