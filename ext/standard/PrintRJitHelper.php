<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * print_r() for compiled JIT/AOT modules (#9190, php-in-PHP).
 *
 * SSOT: {@see VmPrintR}; VM path uses the same formatter via {@see print_r}.
 * php-src: ext/standard/var.c — php_print_r_ex
 */
final class PrintRJitHelper
{
    public static function formatValue(Variable $value): string
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('print_r() JIT helper requires active VM context (#9190)');
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('print_r() JIT helper requires active VM (#9190)');
        }

        return VmPrintR::formatVariable($vm, $value->resolveIndirect());
    }
}
