<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * var_dump() for compiled JIT/AOT modules (#9195, php-in-PHP).
 *
 * SSOT: {@see VmVarDump::dumpVariable()}
 * php-src: ext/standard/var.c — php_var_dump_ex
 *
 * Named formatVariableValue (not dumpValue): LLVM …dumpvalue symbols collide with
 * LLVMDumpValue during nested JIT compile (#16565).
 *
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391 / #23540).
 * Kept off HELPER_RUNTIME_O (runtime_unsafe) until unit.o is class-id safe (#16075 / #23540).
 * No `new VM()` here — NestedJIT of `new VM` fails module verify; HELPER_RUNTIME_O units
 * bake emit-time class ids (#16075). Next: publish Runtime->vm from thin standalone init
 * without breaking NestedJIT property layout, or a scalar IR bridge for int/float.
 * No `: Context` return type — NestedJIT mis-types it at runtime (#20816).
 */
final class VarDumpJitHelper
{
    public static function formatVariableValue(Variable $value, int $flags): ?string
    {
        $ctx = self::requireActiveContext();
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'var_dump() JIT helper requires active VM (#9195 / #23540)'
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
