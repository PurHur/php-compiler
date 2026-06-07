<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for msgpack builtins (php-src ext/msgpack/msgpack.c; issue #7032).
 *
 * Phase 0 skeleton: register symbols; encode/decode in #6551.
 */
abstract class MsgpackFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #6551)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #6551)');
    }
}
