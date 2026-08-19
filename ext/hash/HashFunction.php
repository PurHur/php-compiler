<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for incremental hash builtins (php-src ext/hash/hash.c; #7174, #32464, #32483).
 */
abstract class HashFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHashContext::dispatch($context, $this->getName(), ...$args);
    }
}
