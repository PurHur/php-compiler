<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * var_export() for compiled JIT/AOT modules (#9189, php-in-PHP).
 *
 * SSOT: {@see VmVarExport}; VM path uses the same formatter via {@see var_export}.
 * php-src: ext/standard/var.c — php_var_export_ex
 */
final class VarExportJitHelper
{
    public static function formatValue(Variable $value): string
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            try {
                $ctx = VmActiveContextJitHelper::resolve();
            } catch (\Throwable $e) {
                throw new \LogicException(
                    'var_export() JIT helper requires active VM context (#9189)',
                    0,
                    $e
                );
            }
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            $ctx->runtime->vm = new VM($ctx);
            $vm = $ctx->runtime->vm;
        }

        return VmVarExport::formatVariable($vm, $value->resolveIndirect(), 0, null);
    }
}
