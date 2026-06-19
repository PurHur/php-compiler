<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for clone-with readonly reinit via CloneWithJitHelper PHP (#9498, #9717).
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

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_clone_with_end_runtime',
        'phpc_clone_with_try_consume_literal',
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
        $context->builder->call($context->lookupFunction('phpc_clone_with_end_runtime'), $obj);
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
        $probe = $context->module->getNamedFunction('phpc_clone_with_end_runtime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::ensureValueStringHelpers($context);
        self::registerDeclarations($context);
        self::implementEndBridge($context);
        self::implementTryConsumeLiteralBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');

        self::declareIfMissing($context, 'phpc_clone_with_end_runtime', $void, [$objPtr]);
        self::declareIfMissing($context, 'phpc_clone_with_try_consume_literal', $i1, [$objPtr, $i8p, $i32]);
    }

    private static function declareIfMissing(Context $context, string $name, $ret, array $params): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $ft = $context->context->functionType($ret, false, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);
    }

    private static function implementEndBridge(Context $context): void
    {
        $abiName = 'phpc_clone_with_end_runtime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('cwr_end_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $context->builder->call(
            self::helperFunction($context, self::END_HELPER),
            $context->builder->ptrToInt($obj, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTryConsumeLiteralBridge(Context $context): void
    {
        $abiName = 'phpc_clone_with_try_consume_literal';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $objPtr, $i8p, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('cwr_try_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $name = $fn->getParam(1);
        $len = $fn->getParam(2);
        $nameStr = self::cstrToStringWithLength($context, $name, $context->builder->zExt($len, $i64));
        $ok = $context->builder->call(
            self::helperFunction($context, self::TRY_CONSUME_HELPER),
            $context->builder->ptrToInt($obj, $i64),
            $nameStr
        );
        $context->builder->returnValue($ok);
        $context->registerFunction($abiName, $fn);
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

    private static function cstrToStringWithLength(Context $context, Value $cstr, Value $lenI64): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $charPtr)
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after CloneWithReinitRuntime bridge (#9498)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
