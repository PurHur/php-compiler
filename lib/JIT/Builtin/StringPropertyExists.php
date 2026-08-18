<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for property_exists() via PropertyExistsJitHelper PHP.
 *
     * Replaces inline strcasecmp LLVM in ext/standard/JitPropertyExists.php.
     * SSOT: {@see \PHPCompiler\ext\standard\PropertyExistsJitHelper}.
     * NestedJIT of `: bool` emitted `ret i64 0` into an i1 helper (#31966);
     * existsArgv returns int (0/1) and this bridge truncs to i1.
     * php-src: ext/standard/class.c — PHP_FUNCTION(property_exists)
     */
final class StringPropertyExists
{
    private const ABI = '__phpc_jit_property_exists';

    private const HELPER_PATH = '/ext/standard/PropertyExistsJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\PropertyExistsJitHelper::existsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $objectOrClass, Value $propertyStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $objectOrClass,
            $propertyStr
        );
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

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#16442');

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $valuePtr, $strPtr)
            );

        $savedLowering = $context->loweringLlvmFunction;
        $savedActive = $context->activeFunction;
        $context->activeFunction = self::ABI;
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;
        try {
            $entry = $fn->appendBasicBlock('property_exists_bridge_entry');
            $context->builder->positionAtEnd($entry);

            $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#16442');
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                $helperFn,
                [$fn->getParam(0), $fn->getParam(1)]
            );
            $i64 = $context->getTypeFromString('int64');
            $existsI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
            $exists = JitNestedHelperCoerce::coerceHelperScalarResult($context, $existsI64, $i1);
            $context->builder->returnValue($exists);

            $context->registerFunction(self::ABI, $fn);
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
