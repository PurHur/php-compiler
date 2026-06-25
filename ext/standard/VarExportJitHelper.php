<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
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
            throw new \LogicException('var_export() JIT helper requires active VM context (#9189)');
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_export() JIT helper requires active VM (#9189)');
        }
        $frames = $ctx->runStackFrames();
        $frame = isset($frames[0]) ? $frames[0] : null;
        if (null === $frame) {
            throw new \LogicException('var_export() JIT helper requires an active frame (#9189)');
        }

        return VmVarExport::formatVariable($vm, $value->resolveIndirect(), 0, $frame);
    }
}
