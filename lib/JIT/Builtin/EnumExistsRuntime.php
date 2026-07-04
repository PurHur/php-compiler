<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for enum_exists() via EnumExistsJitHelper PHP (#16169).
 *
 * Replaces LLVM strcasecmp scan in ext/standard/JitEnumExists.php.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(enum_exists)
 */
final class EnumExistsRuntime
{
    private const HELPER_PATH = '/ext/standard/EnumExistsJitHelper.php';

    private const EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\EnumExistsJitHelper::exists';

    private const ABI = '__phpc_jit_enum_exists';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXISTS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, \PHPLLVM\Value $nameStr): \PHPLLVM\Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $nameStr);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $probe = $context->module->getNamedFunction(self::ABI);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('enum_exists_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $hit = $context->builder->call(self::helperFunction($context, self::EXISTS_HELPER), $name);
        $context->builder->returnValue($hit);
        $context->registerFunction(self::ABI, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after EnumExistsJitHelper compile (#16169)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'EnumExistsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('EnumExistsJitHelper.php parseAndCompile failed (#16169)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#16169)');
            }
        }
    }
}
