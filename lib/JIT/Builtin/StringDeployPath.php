<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpc_deploy_path (#585, #9309, #27037, #33225, #33244).
 *
 * Owns `__compiler_phpc_deploy_path` ABI module-locally: {@see getNamedFunction} first, then
 * {@see DeployPathLlvm}. Do not re-add empty always-on shells in {@see Type} —
 * leftover decls mint phpc_deploy_path.1 (#31894 / #32122 / #33225).
 *
 * Thin AOT: pure LLVM getenv+concat (NestedJIT of DeployPathJitHelper SIGSEGVs —
 * peer #26905 / #33217). VM SSOT stays {@see \PHPCompiler\ext\standard\DeployPathJitHelper}.
 */
final class StringDeployPath
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_phpc_deploy_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_phpc_deploy_path', $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StringFilterSanitize #27033).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementDeployPathBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementDeployPathBridge(Context $context): void
    {
        $abiName = '__compiler_phpc_deploy_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        // Mid-invoke ensureLinked: loweringLlvmFunction is the user fn (#33244 / #27211 / #33226).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            DeployPathLlvm::implement($context, $fn);
            $context->registerFunction('__compiler_phpc_deploy_path', $fn);
        });
    }
}
