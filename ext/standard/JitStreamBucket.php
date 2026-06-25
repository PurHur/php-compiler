<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamBucketRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Builtin\StreamBucket;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_bucket_* (#6323, ext/standard/streams.c). */
final class JitStreamBucket
{
    public static function streamBucketNew(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_bucket_new() requires exactly 2 arguments');
        }

        StreamBucket::ensureLinked($context);
        self::rejectEnumCase($context, $args[0], 'stream_bucket_new', 0, 'stream');
        self::rejectEnumCase($context, $args[1], 'stream_bucket_new', 1, 'buffer');

        $bufferStr = JitStringBuiltinArg::lower($context, $args[1], 'stream_bucket_new', 1, 'buffer');

        JitLongArg::lower($context, $args[0], 'stream_bucket_new() stream');

        $bucketHandle = $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_register'),
            $bufferStr
        );

        return self::materializeBucketObject($context, $bucketHandle, $bufferStr);
    }

    public static function streamBucketMakeWriteable(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_bucket_make_writeable() requires exactly 1 argument');
        }

        StreamBucket::ensureLinked($context);
        self::rejectEnumCase($context, $args[0], 'stream_bucket_make_writeable', 0, 'brigade');

        $brigadeHandle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_bucket_make_writeable() brigade'),
            $context->getTypeFromString('int64')
        );

        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $isBrigade = $context->builder->call(
            $context->lookupFunction('__compiler_is_brigade_resource'),
            $brigadeHandle
        );
        $brigadeOk = $context->builder->icmp(Builder::INT_NE, $isBrigade, $zeroI32);
        $failBb = BasicBlockHelper::append($context, 'stream_bucket_mw_bad_brigade');
        $okBb = BasicBlockHelper::append($context, 'stream_bucket_mw_ok_brigade');
        $context->builder->branchIf($brigadeOk, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise(
            $context,
            'stream_bucket_make_writeable(): Argument #1 ($brigade) must be of type resource, '
            .self::typeLabel($context, $args[0]).' given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBb);
        $i64 = $context->getTypeFromString('int64');
        $bucketHandle = $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_brigade_pop'),
            $brigadeHandle
        );
        $empty = $context->builder->icmp(Builder::INT_SLT, $bucketHandle, $i64->constInt(0, false));

        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));

        $hasBb = BasicBlockHelper::append($context, 'stream_bucket_mw_has_bucket');
        $doneBb = BasicBlockHelper::append($context, 'stream_bucket_mw_done');
        $context->builder->branchIf($empty, $doneBb, $hasBb);

        $context->builder->positionAtEnd($hasBb);
        $dataStr = $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_data'),
            $bucketHandle
        );
        $objPtr = self::materializeBucketObject($context, $bucketHandle, $dataStr);
        $hasTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $okBb);
        $result->addIncoming($objPtr, $hasTail);

        return $result;
    }

    public static function streamBucketAppend(Context $context, JITVariable ...$args): Value
    {
        return self::streamBucketQueue($context, 'stream_bucket_append', false, ...$args);
    }

    public static function streamBucketPrepend(Context $context, JITVariable ...$args): Value
    {
        return self::streamBucketQueue($context, 'stream_bucket_prepend', true, ...$args);
    }

    private static function streamBucketQueue(
        Context $context,
        string $function,
        bool $prepend,
        JITVariable ...$args
    ): Value {
        if (2 !== \count($args)) {
            throw new \LogicException($function.'() requires exactly 2 arguments');
        }

        StreamBucket::ensureLinked($context);
        self::rejectEnumCase($context, $args[0], $function, 0, 'brigade');
        self::rejectEnumCase($context, $args[1], $function, 1, 'bucket');

        $brigadeHandle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $function.'() brigade'),
            $context->getTypeFromString('int64')
        );
        $bucketHandle = self::requireBucketHandleFromObject($context, $args[1], $function);

        $i32 = $context->getTypeFromString('int32');
        $isBrigade = $context->builder->call(
            $context->lookupFunction('__compiler_is_brigade_resource'),
            $brigadeHandle
        );
        $brigadeOk = $context->builder->icmp(Builder::INT_NE, $isBrigade, $i32->constInt(0, false));
        $failBb = BasicBlockHelper::append($context, $function.'_bad_brigade');
        $okBb = BasicBlockHelper::append($context, $function.'_ok');
        $context->builder->branchIf($brigadeOk, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise(
            $context,
            $function.'(): Argument #1 ($brigade) must be of type resource, '
            .self::typeLabel($context, $args[0]).' given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBb);
        if ($prepend) {
            self::prependBucket($context, $brigadeHandle, $bucketHandle);
        } else {
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_bucket_brigade_push'),
                $brigadeHandle,
                $bucketHandle
            );
        }

        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }

    private static function prependBucket(Context $context, Value $brigadeHandle, Value $bucketHandle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $existing = $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_brigade_pop'),
            $brigadeHandle
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_brigade_push'),
            $brigadeHandle,
            $bucketHandle
        );
        $empty = $context->builder->icmp(Builder::INT_SL, $existing, $i64->constInt(0, false));
        $skipBb = BasicBlockHelper::append($context, 'stream_bucket_prepend_skip_old');
        $pushBb = BasicBlockHelper::append($context, 'stream_bucket_prepend_push_old');
        $context->builder->branchIf($empty, $skipBb, $pushBb);
        $context->builder->positionAtEnd($pushBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_stream_bucket_brigade_push'),
            $brigadeHandle,
            $existing
        );
        $context->builder->positionAtEnd($skipBb);
    }

    private static function materializeBucketObject(Context $context, Value $bucketHandle, Value $dataStr): Value
    {
        $helper = $context->module->getNamedFunction('__compiler_stream_bucket_object_new');
        if (null !== $helper && $helper->countBasicBlocks() > 0) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_stream_bucket_object_new'),
                $bucketHandle,
                $dataStr
            );
        }

        return self::buildStdClassBucketValue($context, $bucketHandle, $dataStr);
    }

    public static function buildStdClassBucketValue(Context $context, Value $bucketHandle, Value $dataStr): Value
    {
        $objectType = $context->type->object;
        $className = 'stdClass';
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, 'bucket')) {
            $objectType->defineProperty($classId, 'bucket', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($classId, 'data')) {
            $objectType->defineProperty($classId, 'data', JITVariable::TYPE_STRING);
        }

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $bucketVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $bucketHandle
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'bucket'),
            $bucketVar,
            JITVariable::TYPE_NATIVE_LONG
        );

        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $dataVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'data'),
            $dataVar,
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function requireBucketHandleFromObject(
        Context $context,
        JITVariable $arg,
        string $function
    ): Value {
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            TypeErrorRaise::emitRaise(
                $context,
                $function.'(): Argument #2 ($bucket) must be of type object, '
                .self::typeLabel($context, $arg).' given'
            );
            $context->builder->call($context->lookupFunction('abort'));

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $obj = $context->helper->loadValue($arg);
        $bucketProp = $context->type->object->propertyFetch($obj, 'stdClass', 'bucket');
        if (JITVariable::TYPE_NATIVE_LONG !== $bucketProp->type) {
            TypeErrorRaise::emitRaise(
                $context,
                $function.'(): Argument #2 ($bucket) must be an object that has a "bucket" property'
            );
            $context->builder->call($context->lookupFunction('abort'));
        }

        return $context->builder->truncOrBitCast(
            $context->helper->loadValue($bucketProp),
            $context->getTypeFromString('int64')
        );
    }

    private static function rejectEnumCase(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $param
    ): void {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            TypeErrorRaise::emitRaise(
                $context,
                \sprintf(
                    '%s(): Argument #%d ($%s) must be of type %s, %s given',
                    $function,
                    $argIndex + 1,
                    $param,
                    'resource' === $param || 'brigade' === $param || 'stream' === $param ? 'resource' : 'string',
                    $enumLabel
                )
            );
            $context->builder->call($context->lookupFunction('abort'));
        }
    }

    private static function typeLabel(Context $context, JITVariable $arg): string
    {
        return JitOperandTypeLabel::givenLabel($context, $arg);
    }
}
