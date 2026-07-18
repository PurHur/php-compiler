<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Thin standalone AOT sprintf/printf/number_format ABI stubs (#13137, #14811, #20395).
 *
 * Quarantined from {@see StringFormat} so the runtime bridge stays under shrink-test LOC.
 * Gate: {@see Context::isThinStandaloneAotMain()} (peer #20380 / #20371 — no StreamIo defer bag).
 */
final class StringFormatInventoryStubs
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_sprintf',
        '__compiler_printf',
        '__compiler_number_format',
    ];

    public static function implement(Context $context): void
    {
        self::implementSprintfStub($context);
        self::implementPrintfStub($context);
        self::implementNumberFormatStub($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSprintfStub(Context $context): void
    {
        $abiName = '__compiler_sprintf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('sprintf_inv_stub');
        $context->builder->positionAtEnd($entry);
        $fmt = $fn->getParam(0);
        $out = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $context->builder->returnValue($out);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPrintfStub(Context $context): void
    {
        $abiName = '__compiler_printf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('printf_inv_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementNumberFormatStub(Context $context): void
    {
        $abiName = '__compiler_number_format';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $double, $i64, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('number_format_inv_stub');
        $context->builder->positionAtEnd($entry);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('size_t')->constInt(0, false),
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->returnValue($empty);
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFormat inventory stub (#14811)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
