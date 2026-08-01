<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for class_exists() / is_a() / is_subclass_of() via ClassExistsJitHelper (#16185, #26406).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\ClassExistsJitHelper}.
 * php-src: Zend/zend_builtin_functions.c — class_exists / is_a / is_subclass_of
 */
final class StringClassExists
{
    private const ABI = '__phpc_jit_class_exists';

    private const ABI_IS_A = '__phpc_jit_is_a_string';

    private const ABI_IS_SUBCLASS = '__phpc_jit_is_subclass_of_string';

    private const HELPER_PATH = '/ext/standard/ClassExistsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ClassExistsJitHelper::existsArgv';

    private const INVOKE_IS_A = 'PHPCompiler\\ext\\standard\\ClassExistsJitHelper::isAStringArgv';

    private const INVOKE_IS_SUBCLASS = 'PHPCompiler\\ext\\standard\\ClassExistsJitHelper::isSubclassOfStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
        self::INVOKE_IS_A,
        self::INVOKE_IS_SUBCLASS,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
        self::implementIsA($context);
        self::implementIsSubclassOf($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $nameStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $nameStr
        );
    }

    /** is_a($child, $class, true) — runtime autoload (#26406). */
    public static function invokeIsAString(Context $context, Value $childStr, Value $classStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_A),
            $childStr,
            $classStr
        );
    }

    /** is_subclass_of($child, $parent) — runtime autoload (#26406). */
    public static function invokeIsSubclassOfString(Context $context, Value $childStr, Value $parentStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_SUBCLASS),
            $childStr,
            $parentStr
        );
    }

    private static function ensureHelpersCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#16185/#26406');
    }

    private static function implement(Context $context): void
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

        self::ensureHelpersCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('class_exists_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#16185');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0)]
        );
        $exists = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($exists);

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementIsA(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_IS_A);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_IS_A, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureHelpersCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_IS_A,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('is_a_string_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_IS_A, '#26406');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $match = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($match);

        $context->registerFunction(self::ABI_IS_A, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementIsSubclassOf(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_IS_SUBCLASS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_IS_SUBCLASS, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureHelpersCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_IS_SUBCLASS,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('is_subclass_of_string_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_IS_SUBCLASS, '#26406');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $match = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($match);

        $context->registerFunction(self::ABI_IS_SUBCLASS, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
