<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;

/**
 * Thin standalone AOT json_encode ABI stubs (#13245, #14817, #20371).
 *
 * Quarantined from {@see StringJsonEncode} so the runtime bridge stays under shrink-test LOC.
 * Gate: {@see Context::isThinStandaloneAotMain()} (peer #20355 / #20336 — no StreamIo defer bag).
 */
final class StringJsonEncodeInventoryStubs
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_encode_value',
        '__compiler_json_encode_array',
    ];

    public static function implement(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        foreach (self::ABI_FUNCTIONS as $abiName) {
            $probe = $context->module->getNamedFunction($abiName);
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                $context->registerFunction($abiName, $probe);
                continue;
            }

            $valuePtr = $context->getTypeFromString('__value__*');
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $i64 = $context->getTypeFromString('int64');
            $firstParam = '__compiler_json_encode_array' === $abiName ? $htPtr : $valuePtr;
            $ft = $context->context->functionType($strPtr, false, $firstParam, $i64);
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction($abiName, $ft);

            $entry = $fn->appendBasicBlock('json_encode_inv_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($nullStr);
            $context->registerFunction($abiName, $fn);
        }

        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
