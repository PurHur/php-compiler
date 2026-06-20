<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\VmVarFetch;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for $$name guards via VmVarFetch PHP (#10289).
 *
 * php-src: Zend/zend_execute.c — ZEND_FETCH_R/W superglobal branch
 * SSOT: {@see \PHPCompiler\VM\VmVarFetch}
 */
final class VarFetchRuntime
{
    private const HELPER_PATH = '/lib/VM/VmVarFetch.php';

    private const SUPERGLOBAL_HELPER = 'PHPCompiler\\VM\\VmVarFetch::isSuperglobalName';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUPERGLOBAL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__var_fetch__isSuperglobalName');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__var_fetch__isSuperglobalName', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementSuperglobalBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callIsSuperglobalName(Context $context, Value $namePtr): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__var_fetch__isSuperglobalName');

        return $context->builder->call($fn, $namePtr);
    }

    public static function isSuperglobalNameAtCompileTime(string $name): bool
    {
        return VmVarFetch::isSuperglobalName($name);
    }

    private static function implementSuperglobalBridge(Context $context): void
    {
        $abiName = '__var_fetch__isSuperglobalName';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('string*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_fetch_superglobal_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::SUPERGLOBAL_HELPER),
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
            throw new \LogicException($logical.' missing after VmVarFetch compile (#10289)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'VmVarFetch.php');
            if (null === $block) {
                throw new \LogicException('VmVarFetch.php parseAndCompile failed (#10289)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#10289)');
            }
        }
    }
}
