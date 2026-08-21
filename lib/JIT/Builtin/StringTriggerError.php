<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for __compiler_trigger_error LLVM runtime (#7597, #33234).
 *
 * Owns `__compiler_trigger_error` ABI module-locally via {@see StringTriggerErrorJit} /
 * {@see \PHPCompiler\ext\standard\JitTriggerErrorKernel} (`getNamedFunction` first).
 * Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * trigger_error.1 (#31894 / #32122 / #33234).
 */
final class StringTriggerError
{
    public static function ensureLinked(Context $context): void
    {
        StringTriggerErrorJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringTriggerErrorJit::implement($context);
    }
}
