<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * var_dump() for compiled JIT/AOT modules (#9195, php-in-PHP).
 *
 * SSOT: {@see VmVarDump::dumpVariable()}
 * php-src: ext/standard/var.c — php_var_dump_ex
 *
 * Named formatVariableValue (not dumpValue): LLVM …dumpvalue symbols collide with
 * LLVMDumpValue during nested JIT compile (#16565).
 */
final class VarDumpJitHelper
{
    public static function formatVariableValue(Variable $value, int $flags): ?string
    {
        $ctx = self::requireActiveContext();
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_dump() JIT helper requires active VM (#9195)');
        }
        VmVarDump::dumpVariable($vm, $value);

        return null;
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('var_dump() JIT helper requires active VM context (#9195)');
        }

        return $ctx;
    }
}
