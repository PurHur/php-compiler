<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for __compiler_trigger_error / undefined-array-key LLVM runtime (#7597, #33234, #33249).
 *
 * Owns `__compiler_trigger_error` and `__compiler_undefined_array_key_warning_*` ABIs
 * module-locally via {@see StringTriggerErrorJit} /
 * {@see \PHPCompiler\ext\standard\JitTriggerErrorKernel} (`getNamedFunction` first).
 * Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * trigger_error.1 / undefined_array_key_warning_*.1 (#31894 / #32122 / #33234 / #33249).
 * Context ensureMinimal no longer eagerly links this (#34641); ensureFull likewise
 * (#35073). Type::register always-on declare+ensureLinked removed (#35392) —
 * {@see Type\HashTable::implement} declares/ensures at entry; call sites /
 * AssertFail::ensureLinked ensure before lookup.
 * {@see JitTriggerErrorKernel::declareTriggerErrorAbi} before SilenceRuntime NestedJIT (#33253).
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

    /** Declare undef-key ABIs before Type\HashTable::implement lookups (#33249 / #35392). */
    public static function declareUndefinedArrayKeyAbis(Context $context): void
    {
        \PHPCompiler\ext\standard\JitTriggerErrorKernel::declareUndefinedArrayKeyAbis($context);
    }
}
