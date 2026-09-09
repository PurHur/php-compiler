<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time preg_match_all() count when pattern+subject are literals (#36385).
 *
 * Thin AOT NestedJIT cannot scan long subjects for alternation/class patterns
 * (SIGSEGV). Host Zend fold emits a durable int — peer {@see JitPregReplaceCompileTime}.
 * php-src: ext/pcre/php_pcre.c php_pcre_match_impl.
 */
final class JitPregMatchAllCompileTime
{
    /**
     * Count-only (2-arg) fold. Returns boxed long or bool false; null if not foldable.
     */
    public static function tryFoldCount(Context $context, JITVariable $patternArg, JITVariable $subjectArg): ?Value
    {
        $pattern = JitStringBuiltinArg::compileTimeLiteral($patternArg)
            ?? $patternArg->compileTimeString;
        $subject = JitStringBuiltinArg::compileTimeLiteral($subjectArg)
            ?? $subjectArg->compileTimeString;
        if (null === $pattern || null === $subject) {
            return null;
        }
        $n = \preg_match_all($pattern, $subject);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $n) {
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt((int) $n, false));

        return $ptr;
    }
}
