<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for igbinary builtins (php-src ext/igbinary/igbinary.c; issue #7033).
 *
 * Phase 0 skeleton: register symbols; serialize/unserialize in #6573.
 */
abstract class IgbinaryFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #6573)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #6573)');
    }
}
