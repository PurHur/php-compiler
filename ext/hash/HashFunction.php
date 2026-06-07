<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for incremental hash builtins (php-src ext/hash/hash.c; issue #6937).
 *
 * Phase 0 skeleton: register symbols; HashContext lifecycle in #3357.
 */
abstract class HashFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #3357)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #3357)');
    }
}
