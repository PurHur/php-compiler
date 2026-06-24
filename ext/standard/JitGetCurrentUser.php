<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ProcessIdentityJit;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for get_current_user() via ProcessIdentityJitHelper PHP (#9017, was getpwuid LLVM). */
final class JitGetCurrentUser
{
    public static function invoke(Context $context): Value
    {
        return ProcessIdentityJit::getCurrentUser($context);
    }
}
