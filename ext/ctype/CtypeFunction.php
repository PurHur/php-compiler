<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for ctype builtins (php-src ext/ctype/ctype.c; issue #6837).
 *
 * Phase 0 skeleton: register symbols; full parity in #3381.
 */
abstract class CtypeFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #3381)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #3381)');
    }
}
