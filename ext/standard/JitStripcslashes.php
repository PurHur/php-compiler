<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helper for stripcslashes() — VmString parity (#3356, #5652). */
final class JitStripcslashes
{
    public static function unescape(Context $context, JITVariable $subjectArg): Value
    {
        $subjectLit = JitStringArg::compileTimeLiteral($subjectArg) ?? $subjectArg->compileTimeString;
        if (null !== $subjectLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::stripcslashes($subjectLit))
            );
        }

        StringCslashes::ensureStripcslashes($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_stripcslashes'),
            JitStringArg::lower($context, $subjectArg, 'stripcslashes() argument #1')
        );
    }
}
