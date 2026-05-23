<?php

declare(strict_types=1);

/**
 * Self-host JIT\Result stub smoke test (#816).
 * Bundled Result methods must not compile FFI::new/memcpy during native link.
 */

putenv('PHP_COMPILER_SELFHOST_AOT=1');

require_once __DIR__.'/../../lib/Handler.php';
require_once __DIR__.'/../../lib/Func.php';
require_once __DIR__.'/../../lib/Func/JIT.php';
require_once __DIR__.'/../../lib/JIT/Builtin.php';
require_once __DIR__.'/../../lib/JIT/Result.php';

echo "ok\n";
