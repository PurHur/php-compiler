<?php

declare(strict_types=1);

/**
 * Superglobals::isSuperglobalName compile-time switch (self-host AOT real lowering).
 */

use PHPCompiler\Web\Superglobals;

require_once __DIR__.'/../../lib/Web/Superglobals.php';

echo Superglobals::isSuperglobalName('_GET') ? '1' : '0';
echo Superglobals::isSuperglobalName('foo') ? '1' : '0';
