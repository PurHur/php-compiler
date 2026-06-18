<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for phpc_deploy_path() via DeployPathJitHelper / {@see \PHPCompiler\JIT\Builtin\StringDeployPath} (#9309). */
final class JitDeployPath
{
    /** @return Value */
    public static function invoke(Context $context, Value $rel, Value $fallback): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_phpc_deploy_path'),
            $rel,
            $fallback
        );
    }
}
