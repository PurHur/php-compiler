<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for class_exists() / is_a() / is_subclass_of() via ClassExistsJitHelper (#16185, #26406, #32706).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\ClassExistsJitHelper}.
 * NestedJIT of `: bool` emitted `ret i64 0` into an i1 helper (#32701); helpers return int 0/1.
 * php-src: Zend/zend_builtin_functions.c
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

    /** is_a($child, $class, true) — boxed string subject (#32706). */
    public static function invokeIsAString(Context $context, Value $childBoxPtr, Value $classStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_A),
            $childBoxPtr,
            $classStr
        );
    }

    /** is_subclass_of($child, $parent) — boxed string subject (#32706). */
    public static function invokeIsSubclassOfString(Context $context, Value $childBoxPtr, Value $parentStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_SUBCLASS),
            $childBoxPtr,
            $parentStr
        );
    }

    private static function ensureHelpersCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16185/#26406/#32706',
            true
        );
    }

    private static function implement(Context $context): void
    {
        self::emitI1Bridge(
            $context,
            self::ABI,
            self::INVOKE_HELPER,
            '#16185',
            'class_exists_bridge_entry',
            false
        );
    }

    private static function implementIsA(Context $context): void
    {
        self::emitI1Bridge(
            $context,
            self::ABI_IS_A,
            self::INVOKE_IS_A,
            '#26406/#32706',
            'is_a_string_bridge_entry',
            true
        );
    }

    private static function implementIsSubclassOf(Context $context): void
    {
        self::emitI1Bridge(
            $context,
            self::ABI_IS_SUBCLASS,
            self::INVOKE_IS_SUBCLASS,
            '#26406/#32706',
            'is_subclass_of_string_bridge_entry',
            true
        );
    }

    private static function emitI1Bridge(
        Context $context,
        string $abi,
        string $invokeHelper,
        string $issue,
        string $entryName,
        bool $twoArgs
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureHelpersCompiled($context);

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abi,
                $twoArgs
                    ? $context->context->functionType($i1, false, $valuePtr, $strPtr)
                    : $context->context->functionType($i1, false, $strPtr)
            );

        $savedLowering = $context->loweringLlvmFunction;
        $savedActive = $context->activeFunction;
        $context->activeFunction = $abi;
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;
        try {
            $entry = $fn->appendBasicBlock($entryName);
            $context->builder->positionAtEnd($entry);

            $helperFn = JitVmHelperLink::lookupCompiled($context, $invokeHelper, $issue);
            $args = $twoArgs ? [$fn->getParam(0), $fn->getParam(1)] : [$fn->getParam(0)];
            $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, $args);
            $i64 = $context->getTypeFromString('int64');
            $existsI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
            $exists = JitNestedHelperCoerce::coerceHelperScalarResult($context, $existsI64, $i1);
            $context->builder->returnValue($exists);

            $context->registerFunction($abi, $fn);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                $context->builder->positionAtEnd($savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }
}
