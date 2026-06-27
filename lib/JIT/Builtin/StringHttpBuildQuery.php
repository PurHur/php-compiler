<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_http_build_query via HttpBuildQueryJitHelper PHP (#9443).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\HttpBuildQueryJitHelper}; thin LLVM
 * bridge forwards the ABI. php-src: ext/standard/http.c — http_build_query
 */
final class StringHttpBuildQuery
{
    private const HELPER_PATH = '/ext/standard/HttpBuildQueryJitHelper.php';

    private const BUILD_HELPER = 'PHPCompiler\\ext\\standard\\HttpBuildQueryJitHelper::build';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_http_build_query',
    ];

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
        $probe = $context->module->getNamedFunction('__compiler_http_build_query');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBuildBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBuildBridge(Context $context): void
    {
        $abiName = '__compiler_http_build_query';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('http_build_query_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::BUILD_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
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
            throw new \LogicException($logical.' missing after HttpBuildQueryJitHelper compile (#9443)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HttpBuildQueryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HttpBuildQueryJitHelper.php parseAndCompile failed (#9443)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9443)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHttpBuildQuery bridge (#9443)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
