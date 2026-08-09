<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitIsResource;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ResourceArrayOffsetSupport;
use PHPLLVM\Value;

/**
 * JIT: resource array offsets warn + cast to int (#29550, zend_hash.c).
 *
 * Handles are stored as native longs (often typed TYPE_OBJECT via php-types
 * {@code resource}); {@see JitIsResource} + ptrToInt recovers the list id.
 */
final class HashTableResourceKeyLlvm
{
    /**
     * Emit Zend resource-offset E_WARNING for a known handle (int64).
     */
    public static function emitWarning(Context $context, Value $handleI64): void
    {
        StringTriggerError::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $handle = $handleI64->typeOf() === $i64
            ? $handleI64
            : $context->builder->zExt($handleI64, $i64);

        // Build message at runtime — id is not a compile-time constant.
        $bufSize = $sizeT->constInt(96, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString(ResourceArrayOffsetSupport::WARNING_FORMAT_LL),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $handle,
            $handle
        );
        $msgLen = $context->builder->zExt($written, $sizeT);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $context->builder->pointerCast($bufChar, $i8p),
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(max(0, $context->callSiteLine), false)
        );
    }

    /** ptrToInt of a TYPE_OBJECT dim that may hold a resource handle (#29550). */
    public static function handleFromObjectDim(Context $context, Variable $dim): Value
    {
        return $context->builder->ptrToInt(
            $context->helper->loadValue($dim),
            $context->getTypeFromString('int64')
        );
    }

    /**
     * TYPE_OBJECT dim: if resource handle, warn + invoke $onIndex(size_t); else illegal offset.
     *
     * @param callable(Value): void $onIndex receives size_t index; must branch to join
     */
    public static function emitObjectDimOrIllegal(
        Context $context,
        Variable $dim,
        string $legacyIllegalMessage,
        callable $onIndex
    ): void {
        $handle = self::handleFromObjectDim($context, $dim);
        $isRes = JitIsResource::invoke($context, $handle);
        $resBlock = BasicBlockHelper::append($context, 'ht_res_key_ok');
        $illegalBlock = BasicBlockHelper::append($context, 'ht_res_key_illegal');
        $context->builder->branchIf($isRes, $resBlock, $illegalBlock);

        $context->builder->positionAtEnd($resBlock);
        self::emitWarning($context, $handle);
        $index = $context->builder->truncOrBitCast(
            $handle,
            $context->getTypeFromString('size_t')
        );
        $onIndex($index);

        $context->builder->positionAtEnd($illegalBlock);
        HashTableHelper::emitIllegalOffsetTypeForKey($context, $dim, $legacyIllegalMessage);
    }
}
