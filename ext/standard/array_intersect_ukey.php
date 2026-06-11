<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_intersect_ukey() — key intersect with user comparator (php-src ext/standard/array.c; #4107). */
final class array_intersect_ukey extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::intersectUkey($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_intersect_ukey() is VM-only in this compiler build');
    }
}
