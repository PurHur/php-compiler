<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for clone-with readonly reinit via CloneWithJitHelper PHP (#9498, #9717, #10108).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} so NestedJIT restores
 * scope->className and jitEnclosingBlock (raw compile leaked CloneWithJitHelper into
 * asymmetric set Error wording — #26873).
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#9498/#26873');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9498/#26873'
        );
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
}
