<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_push() (#12719, #22801, #27226).
 *
 * Thin AOT NestedJIT of ArrayPushJitHelper::pushFromList died on
 * foreach ($ht->iterate()) (CFG types Traversable as Variable ->
 * ObjectPropertyForeach null slot, #27226). Append at the call site with
 * ArrayBuiltinHelper::appendElement instead. VM still uses ArrayPushJitHelper.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\array_push}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_push)
 */
final class ArrayPushRuntime
{
    public static function push(Context $context, JITVariable $array, JITVariable ...$values): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $native = ArrayBuiltinHelper::isNativeArray($array->type);
        foreach ($values as $value) {
            ArrayBuiltinHelper::appendElement($context, $ht, $value);
        }
        if ($native) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }

        return ArrayBuiltinHelper::getNumElements($context, $ht);
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
        $probe = $context->module->getNamedFunction('__array_push__count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementIfMissing($context, '__array_push__count', self::implementCountBridge(...));
        self::implementIfMissing($context, '__array_push__append', self::implementAppendBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @param callable(Context, LlvmFunction): void $emit */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');

        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $i64,
                false,
                ...match ($name) {
                    '__array_push__count' => [$htPtr],
                    '__array_push__append' => [$htPtr, $htPtr],
                    default => throw new \LogicException('unknown array_push bridge: '.$name),
                }
            )
        );
    }

    private static function implementCountBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_push_count_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            ArrayBuiltinHelper::getNumElements($context, $fn->getParam(0))
        );
    }

    private static function implementAppendBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('array_push_append_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $dest = $fn->getParam(0);
        $valuesHt = $fn->getParam(1);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->truncOrBitCast(
            ArrayBuiltinHelper::getNumElements($context, $valuesHt),
            $sizeT
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_push_append_head');
        $body = BasicBlockHelper::append($context, 'array_push_append_body');
        $done = BasicBlockHelper::append($context, 'array_push_append_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $value = HashTableHelper::readIndexedToValueBox($context, $valuesHt, $idx);
        ArrayBuiltinHelper::appendElement($context, $dest, $value);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue(ArrayBuiltinHelper::getNumElements($context, $dest));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__array_push__count', '__array_push__append'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ArrayPushRuntime bridge (#12719)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
