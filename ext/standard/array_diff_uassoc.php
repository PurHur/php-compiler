<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_diff_uassoc() — exact-key diff with user value comparator (php-src ext/standard/array.c; #5644). */
final class array_diff_uassoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::diffUassoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_diff_uassoc() is VM-only in this compiler build');
    }
}
