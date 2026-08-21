<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringDeployPath;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_deploy_path() via {@see StringDeployPath} / DeployPathLlvm (#9309, #33225, #33244).
 *
 * Call-site {@see StringDeployPath::ensureLinked} before lookup (Type always-on shell dropped).
 */
final class JitDeployPath
{
    /** @return Value */
    public static function invoke(Context $context, Value $rel, Value $fallback): Value
    {
        StringDeployPath::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_phpc_deploy_path'),
            $rel,
            $fallback
        );
    }
}
