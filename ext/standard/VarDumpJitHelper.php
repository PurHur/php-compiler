<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * var_dump() for compiled JIT/AOT modules (#9195, php-in-PHP).
 *
 * SSOT: {@see VmVarDump::dumpVariable()} / {@see VmVarDump::tryDumpWithoutVm()}
 * php-src: ext/standard/var.c — php_var_dump_ex
 *
 * Named formatVariableValue (not dumpValue): LLVM …dumpvalue symbols collide with
 * LLVMDumpValue during nested JIT compile (#16565).
 *
 * Thin standalone AOT (#23540): scalars dump via {@see VmVarDump::tryDumpWithoutVm}
 * — never touch `$ctx->runtime->vm` (NestedJIT class-id layout segfault). Array/object
 * still need active Context + Runtime->vm once thin init publishes a matching layout.
 * Kept off HELPER_RUNTIME_O (runtime_unsafe) until unit.o is class-id safe (#16075).
 * No `: Context` return type — NestedJIT mis-types it at runtime (#20816).
 */
final class VarDumpJitHelper
{
    public static function formatVariableValue(Variable $value, int $flags): ?string
    {
        // Scalars first — avoids Context/runtime property access under thin AOT (#23540).
        if (VmVarDump::tryDumpWithoutVm($value)) {
            return null;
        }

        $ctx = self::requireActiveContext();
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'var_dump() JIT helper requires active VM for non-scalar values (#9195 / #23540)'
            );
        }
        VmVarDump::dumpVariable($vm, $value);

        return null;
    }

    /** @return \PHPCompiler\VM\Context */
    private static function requireActiveContext()
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }

        return $ctx;
    }
}
