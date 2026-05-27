<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: Web\Superglobals::readRequestBody() should lower under self-host AOT stubs.
 */

use PHPCompiler\Web\Superglobals;

putenv('REQUEST_BODY=hello-from-env');
echo Superglobals::readRequestBody();

