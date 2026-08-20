<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for class_exists() / is_a() / is_subclass_of() via ClassExistsJitHelper (#16185, #26406).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\ClassExistsJitHelper}.
 * NestedJIT of `: bool` emitted `ret i64 0` into an i1 helper (#32706 leftover of #32701);
 * helpers return int (0/1) and this bridge truncs to i1.
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
        self::implementStringBridge(
            $context,
            self::ABI,
            'class_exists_bridge_entry',
            self::INVOKE_HELPER,
            '#16185',
            1
        );
    }

    private static function implementIsA(Context $context): void
    {
        self::implementStringBridge(
            $context,
            self::ABI_IS_A,
            'is_a_string_bridge_entry',
            self::INVOKE_IS_A,
            '#26406',
            2
        );
    }

    private static function implementIsSubclassOf(Context $context): void
    {
        self::implementStringBridge(
            $context,
            self::ABI_IS_SUBCLASS,
            'is_subclass_of_string_bridge_entry',
            self::INVOKE_IS_SUBCLASS,
            '#26406',
            2
        );
    }

    private static function implementStringBridge(
        Context $context,
        string $abi,
        string $entryName,
        string $helperLogical,
        string $ticket,
        int $stringArgc
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

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $paramTys = 2 === $stringArgc ? [$strPtr, $strPtr] : [$strPtr];
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abi,
                $context->context->functionType($i1, false, ...$paramTys)
            );

        $savedLowering = $context->loweringLlvmFunction;
        $savedActive = $context->activeFunction;
        $context->activeFunction = $abi;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        try {
            $entry = $fn->appendBasicBlock($entryName);
            $context->builder->positionAtEnd($entry);

            $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, $ticket);
            $callArgs = [$fn->getParam(0)];
            if (2 === $stringArgc) {
                $callArgs[] = $fn->getParam(1);
            }
            $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, $callArgs);
            $i64 = $context->getTypeFromString('int64');
            $asI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
            $asI1 = JitNestedHelperCoerce::coerceHelperScalarResult($context, $asI64, $i1);
            $context->builder->returnValue($asI1);

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
