<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_intersect_uassoc() — exact-key intersect with user value comparator (php-src ext/standard/array.c; #4285). */
final class array_intersect_uassoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::intersectUassoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_intersect_uassoc() is VM-only in this compiler build');
    }
}
