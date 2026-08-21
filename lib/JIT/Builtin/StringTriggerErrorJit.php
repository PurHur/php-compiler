<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitTriggerErrorKernel;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __compiler_trigger_error via TriggerErrorJitHelper PHP (#9293, #19864, #21300, #33234).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitTriggerErrorKernel}
 * (embed + standalone; no thin no-op ABI fork).
 * php-src: Zend/zend_execute_API.c, main/php_errors.c
 *
 * Do not re-add an always-on empty decl in {@see \PHPCompiler\JIT\Builtin\Type} —
 * leftover decls mint trigger_error.1 (#31894 / #32122 / #33234).
 */
final class StringTriggerErrorJit
{
    public static function implement(Context $context): void
    {
        JitTriggerErrorKernel::implement($context);
    }

    /** Load libc stderr FILE* (external global), matching StreamGlobalsJit. */
    public static function stderrFilePtr(Context $context): Value
    {
        return JitTriggerErrorKernel::stderrFilePtr($context);
    }
}
