<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for gd builtins (php-src ext/gd/gd.c; issue #7407).
 *
 * Phase 0 skeleton: register symbols; libgd drawing parity in #3496.
 */
abstract class GdFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #3496)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #3496)');
    }
}
