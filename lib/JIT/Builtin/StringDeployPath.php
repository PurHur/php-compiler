<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpc_deploy_path via DeployPathJitHelper PHP (#585, #9309).
 */
final class StringDeployPath
{
    private const HELPER_PATH = '/ext/standard/DeployPathJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\DeployPathJitHelper::resolve';

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

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction('__compiler_phpc_deploy_path');

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('deploy_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_phpc_deploy_path', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::RESOLVE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::RESOLVE_HELPER.' missing after DeployPathJitHelper compile (#9309)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::RESOLVE_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'DeployPathJitHelper.php');
        if (null === $block) {
            throw new \LogicException('DeployPathJitHelper.php parseAndCompile failed (#9309)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(self::RESOLVE_HELPER.' was not compiled for JIT (#9309)');
        }
    }
}
