<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_builtin_function_exists via FunctionExistsJitHelper PHP (#9239).
 *
 * Replaces the former LLVM binary-search table over ext builtin names.
 * php-src: ext/standard/basic_functions.c — function_exists / function_table
 */
final class FunctionExistsRuntime
{
    private const HELPER_PATH = '/ext/standard/FunctionExistsJitHelper.php';

    private const BUILTIN_EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\FunctionExistsJitHelper::builtinExists';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILTIN_EXISTS_HELPER,
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
        $probe = $context->module->getNamedFunction('__compiler_builtin_function_exists');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_builtin_function_exists', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBuiltinExistsBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBuiltinExistsBridge(Context $context): void
    {
        $abiName = '__compiler_builtin_function_exists';
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fe_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $hit = $context->builder->call(self::helperFunction($context, self::BUILTIN_EXISTS_HELPER), $name);
        $context->builder->returnValue($context->builder->zext($hit, $i64));
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FunctionExistsJitHelper compile (#9239)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FunctionExistsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('FunctionExistsJitHelper.php parseAndCompile failed (#9239)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9239)');
            }
        }
    }
}
