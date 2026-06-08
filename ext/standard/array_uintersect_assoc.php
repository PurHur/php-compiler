<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_uintersect_assoc() — key intersect with user comparator (php-src ext/standard/array.c; #5644). */
final class array_uintersect_assoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::uintersectAssoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_uintersect_assoc() is VM-only in this compiler build');
    }
}
