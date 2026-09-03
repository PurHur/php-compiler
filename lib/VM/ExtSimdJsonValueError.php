<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Marker for PECL simdjson depth/arg ValueError — implemented in ext/simdjson (#36204).
 */
interface ExtSimdJsonValueError extends \Throwable
{
}
