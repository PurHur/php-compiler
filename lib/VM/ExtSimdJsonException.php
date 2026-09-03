<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Marker for PECL simdjson decode/key errors — implemented in ext/simdjson (#36204).
 */
interface ExtSimdJsonException extends \Throwable
{
}
