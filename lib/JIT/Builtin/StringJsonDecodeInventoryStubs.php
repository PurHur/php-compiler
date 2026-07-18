<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Thin standalone AOT json_decode/json_validate ABI stubs (#13245, #14812, #20380).
 *
 * Quarantined from {@see StringJsonDecode} so the runtime bridge stays under shrink-test LOC.
 */
final class StringJsonDecodeInventoryStubs
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_decode',
        '__compiler_json_validate',
        '__compiler_json_last_error',
        '__compiler_json_last_error_msg',
    ];

    public static function implement(Context $context): void
    {
        self::implementDecodeStub($context);
        self::implementValidateStub($context);
        self::implementLastErrorStub($context);
        self::implementLastErrorMsgStub($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementDecodeStub(Context $context): void
    {
        $abiName = '__compiler_json_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_decode_inv_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $fn->getParam(1)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementValidateStub(Context $context): void
    {
        $abiName = '__compiler_json_validate';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_validate_inv_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorStub(Context $context): void
    {
        $abiName = '__compiler_json_last_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_last_error_inv_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorMsgStub(Context $context): void
    {
        $abiName = '__compiler_json_last_error_msg';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('json_last_error_msg_inv_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringJsonDecode inventory stub (#14812)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
