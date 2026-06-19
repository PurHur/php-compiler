<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for clone-with readonly reinit via CloneWithJitHelper PHP (#9498, #9717, #10108).
 *
 * VM SSOT: {@see \PHPCompiler\VM\CloneWithSupport}
 * php-src: Zend/zend_objects.c — IS_PROP_REINITABLE during clone-with
 */
final class CloneWithReinitRuntime
{
    private const MAX_PROPS = 16;

    private const HELPER_PATH = '/ext/standard/CloneWithJitHelper.php';

    private const BEGIN_HELPER = 'PHPCompiler\\ext\\standard\\CloneWithJitHelper::begin';

    private const ADD_PROPERTY_HELPER = 'PHPCompiler\\ext\\standard\\CloneWithJitHelper::addProperty';

    private const END_HELPER = 'PHPCompiler\\ext\\standard\\CloneWithJitHelper::end';

    private const TRY_CONSUME_HELPER = 'PHPCompiler\\ext\\standard\\CloneWithJitHelper::tryConsume';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BEGIN_HELPER,
        self::ADD_PROPERTY_HELPER,
        self::END_HELPER,
        self::TRY_CONSUME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    /** @param list<string> $names */
    public static function emitBegin(Context $context, Value $obj, array $names): void
    {
        self::ensureLinked($context);
        $count = \count($names);
        if ($count > self::MAX_PROPS) {
            throw new \LogicException('phpc_clone_with_begin() supports at most '.self::MAX_PROPS.' properties');
        }

        $i64 = $context->getTypeFromString('int64');
        $objAddr = $context->builder->ptrToInt($obj, $i64);
        $context->builder->call(self::helperFunction($context, self::BEGIN_HELPER), $objAddr);
        foreach ($names as $name) {
            if (\strlen($name) >= 64) {
                throw new \LogicException('clone-with property name too long for JIT reinit window');
            }
            $nameStr = self::literalToString($context, $name);
            $context->builder->call(self::helperFunction($context, self::ADD_PROPERTY_HELPER), $nameStr);
        }
    }

    public static function emitEnd(Context $context, Value $obj): void
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::END_HELPER),
            $context->builder->ptrToInt($obj, $i64)
        );
    }

    public static function emitTryConsumePropertyName(Context $context, Value $obj, string $propName): Value
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $objAddr = $context->builder->ptrToInt($obj, $i64);
        $nameStr = self::literalToString($context, $propName);

        return $context->builder->call(
            self::helperFunction($context, self::TRY_CONSUME_HELPER),
            $objAddr,
            $nameStr
        );
    }

    public static function implement(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        self::ensureJitHelperCompiled($context);
    }

    private static function literalToString(Context $context, string $literal): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = \strlen($literal);
        $namePtr = $context->constantFromString($literal);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt($len, false),
            $context->builder->pointerCast($namePtr, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CloneWithJitHelper compile (#9498)');
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

        self::ensureValueStringHelpers($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CloneWithJitHelper.php');
            if (null === $block) {
                throw new \LogicException('CloneWithJitHelper.php parseAndCompile failed (#9498)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
            $context->markJitIncludedFileCompiled($realPath);
        } finally {
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
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
                throw new \LogicException($lc.' was not compiled for JIT (#9498)');
            }
        }
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
