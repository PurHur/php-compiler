<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helper for addcslashes() — VmString parity via CslashesJitHelper (#3356, #5652, #9578). */
final class JitAddcslashes
{
    public static function escape(Context $context, JITVariable $subjectArg, JITVariable $charlistArg): Value
    {
        $subjectLit = JitStringArg::compileTimeLiteral($subjectArg) ?? $subjectArg->compileTimeString;
        $charlistLit = JitStringArg::compileTimeLiteral($charlistArg) ?? $charlistArg->compileTimeString;
        if (null !== $subjectLit && null !== $charlistLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::addcslashes($subjectLit, $charlistLit))
            );
        }

        StringCslashes::ensureLinked($context);
        $subject = JitStringArg::lower($context, $subjectArg, 'addcslashes() argument #1');
        if (null !== $charlistLit) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_addcslashes'),
                $subject,
                $context->builder->load($context->constantStringFromString($charlistLit))
            );
        }
        $charlist = JitStringArg::lower($context, $charlistArg, 'addcslashes() argument #2');

        return $context->builder->call(
            $context->lookupFunction('__compiler_addcslashes'),
            $subject,
            $charlist
        );
    }
}
