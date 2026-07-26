<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * print_r() for compiled JIT/AOT modules (#9190, php-in-PHP).
 *
 * SSOT: {@see VmPrintR}; VM path uses the same formatter via {@see print_r}.
 * php-src: ext/standard/var.c — php_print_r_ex
 *
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391 / #23540).
 * Kept off HELPER_RUNTIME_O (runtime_unsafe) — peer VarExportJitHelper / #23540.
 * No `new VM()` here — NestedJIT module-verify / class-id hazards (#16075).
 */
final class PrintRJitHelper
{
    public static function formatValue(Variable $value): string
    {
        // Inline context resolve — NestedJIT mis-types `: Context` returns as int (#20816).
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'print_r() JIT helper requires Runtime->vm from thin standalone init (#9190 / #23540)'
            );
        }

        return VmPrintR::formatVariable($vm, $value->resolveIndirect());
    }
}
