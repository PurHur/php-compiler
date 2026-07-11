<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for count(COUNT_NORMAL) (#13276, #15417).
 *
 * Thin LLVM bridge reads {@see __hashtable__::numElements} via {@see ArrayBuiltinHelper::getNumElements()}
 * — avoids nested {@see ArrayCountJitHelper} JIT during user-script AOT init (segfault #14578).
 * SSOT: {@see \PHPCompiler\VM\HashTable::getNumElements()}
 * php-src: ext/standard/array.c — php_count
 */
final class ArrayCountRuntime
{
    private const ABI_NUM = '__array_count__numElements';

    public static function numElements(Context $context, JITVariable $array): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            return ArrayBuiltinHelper::getNumElements(
                $context,
                ArrayBuiltinHelper::loadHashTable($context, $array)
            );
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $count = $context->builder->call(
            $context->lookupFunction(self::ABI_NUM),
            $ht
        );

        return $context->builder->zExt($count, $context->getTypeFromString('int64'));
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NUM);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementDirectLlvmBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementDirectLlvmBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NUM);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            try {
                $blocks = $probe->getBasicBlocks();
                $entry = $blocks[0] ?? null;
                if (null !== $entry && null !== $entry->getTerminator()) {
                    $context->registerFunction(self::ABI_NUM, $probe);

                    return;
                }
            } catch (\Throwable) {
            }
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_NUM,
                $context->context->functionType($sizeT, false, $htPtr)
            );

        $entry = $fn->countBasicBlocks() > 0
            ? self::openBridgeEntry($fn)
            : $fn->appendBasicBlock('array_count_normal_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $numI64 = ArrayBuiltinHelper::getNumElements($context, $fn->getParam(0));
        $count = $context->builder->trunc($numI64, $sizeT);
        $context->builder->returnValue($count);
        $context->registerFunction(self::ABI_NUM, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function openBridgeEntry(LlvmFunction $fn): \PHPLLVM\BasicBlock
    {
        try {
            $blocks = $fn->getBasicBlocks();
            $entry = $blocks[0] ?? null;
            if (null !== $entry && null === $entry->getTerminator()) {
                return $entry;
            }
        } catch (\Throwable) {
        }

        return $fn->appendBasicBlock('array_count_normal_bridge_entry');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NUM);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NUM.' missing after ArrayCountRuntime bridge (#13276)');
        }
        $context->registerFunction(self::ABI_NUM, $fn);
    }
}
