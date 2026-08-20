<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ChownRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for chown()/lchown() via __compiler_chown (php-in-PHP ChownRuntime; #30167). */
final class JitChown
{
    /** @return Value true when __compiler_chown returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $userVal, bool $lchown): Value
    {
        return ChownRuntime::invokeChown($context, $pathStr, $userVal, $lchown);
    }
}
