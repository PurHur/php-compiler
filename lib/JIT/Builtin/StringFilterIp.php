<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_validate_ip via FilterIpJitHelper PHP (#4403).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_ip
 */
final class StringFilterIp
{
    private const HELPER_PATH = '/ext/filter/FilterIpJitHelper.php';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\filter\\FilterIpJitHelper::validate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_ip',
    ];

    public static function ensureLinked(Context $context): void
    {
        $restore = $context->builder->getInsertBlock();
        self::implement($context);
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_ip');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementValidateBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_ip';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_ip_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::VALIDATE_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FilterIpJitHelper compile (#4403)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FilterIpJitHelper.php');
            if (null === $block) {
                throw new \LogicException('FilterIpJitHelper.php parseAndCompile failed (#4403)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#4403)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterIp bridge (#4403)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
