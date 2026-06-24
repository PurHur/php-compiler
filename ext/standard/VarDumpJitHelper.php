<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * var_dump() for compiled JIT/AOT modules (#9195, php-in-PHP).
 *
 * SSOT: {@see VmVarDump}; VM path uses the same formatter via {@see var_dump_}.
 * php-src: ext/standard/var.c — php_var_dump_ex
 */
final class VarDumpJitHelper
{
    public static function dumpValue(Variable $value): void
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('var_dump() JIT helper requires active VM context (#9195)');
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_dump() JIT helper requires active VM (#9195)');
        }
        VmVarDump::dumpVariable($vm, $value->resolveIndirect());
    }
}
