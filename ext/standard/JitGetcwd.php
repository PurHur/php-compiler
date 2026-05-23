<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for getcwd() via __compiler_getcwd. */
final class JitGetcwd
{
    public static function invoke(Context $context): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_getcwd')
        );
    }
}
