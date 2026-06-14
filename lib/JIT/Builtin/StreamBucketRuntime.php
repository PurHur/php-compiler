<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream filter bucket brigades — mirrors ext/standard/VmStreamBucket.php (#6323, #7089).
 *
 * php-src: ext/standard/streams.c — stream_bucket_new, stream_bucket_make_writeable
 */
final class StreamBucketRuntime
{
    public const BUCKET_HANDLE_BASE = 0x30000000;

    public const BRIGADE_HANDLE_BASE = 0x40000000;

    private const MAX_BUCKETS = 256;

    private const MAX_BRIGADES = 64;

    private const MAX_QUEUE = 32;

    private const GLOBAL_BUCKET_ACTIVE = 'phpc_bucket_active';

    private const GLOBAL_BUCKET_DATA = 'phpc_bucket_data';

    private const GLOBAL_BRIGADE_ACTIVE = 'phpc_brigade_active';

    private const GLOBAL_BRIGADE_COUNT = 'phpc_brigade_count';

    private const GLOBAL_BRIGADE_QUEUE = 'phpc_brigade_queue';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_bucket_register',
        '__compiler_stream_bucket_data',
        '__compiler_is_bucket_resource',
        '__compiler_is_brigade_resource',
        '__compiler_stream_brigade_alloc',
        '__compiler_stream_bucket_brigade_push',
        '__compiler_stream_bucket_brigade_pop',
        '__compiler_stream_bucket_object_new',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::registerDeclarations($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implementBodies($context);
        }
    }

    /** Standalone AOT: emit bucket runtime into the module during Context init (#6323). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerDeclarations($context);
        self::implementBodies($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        self::ensureGlobals($context);
        self::ensureLibc($context);
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            self::declareFunction($context, $name);
        }
    }

    public static function implement(Context $context): void
    {
        self::registerDeclarations($context);
        self::implementBodies($context);
    }

    private static function implementBodies(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        $probe = $context->module->getNamedFunction('__compiler_stream_bucket_register');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__compiler_stream_bucket_register', self::emitBucketRegister(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_data', self::emitBucketData(...));
        self::implementIfMissing($context, '__compiler_is_bucket_resource', self::emitIsBucketResource(...));
        self::implementIfMissing($context, '__compiler_is_brigade_resource', self::emitIsBrigadeResource(...));
        self::implementIfMissing($context, '__compiler_stream_brigade_alloc', self::emitBrigadeAlloc(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_brigade_push', self::emitBrigadePush(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_brigade_pop', self::emitBrigadePop(...));
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementIfMissing($context, '__compiler_stream_bucket_object_new', self::emitBucketObjectNew(...));
        }

        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
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
        if (null !== self::captureInsertBlock($context)) {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $ft = match ($name) {
            '__compiler_stream_bucket_register' => $context->context->functionType($i64, false, $strPtr),
            '__compiler_stream_bucket_data' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_is_bucket_resource', '__compiler_is_brigade_resource' => $context->context->functionType($i32, false, $i64),
            '__compiler_stream_brigade_alloc' => $context->context->functionType($i64, false),
            '__compiler_stream_bucket_brigade_push' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_stream_bucket_brigade_pop' => $context->context->functionType($i64, false, $i64),
            '__compiler_stream_bucket_object_new' => $context->context->functionType(
                $context->getTypeFromString('__value__*'),
                false,
                $i64,
                $strPtr
            ),
            default => throw new \LogicException('StreamBucketRuntime: unknown '.$name),
        };

        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            self::GLOBAL_BUCKET_ACTIVE => $i8->arrayType(self::MAX_BUCKETS),
            self::GLOBAL_BUCKET_DATA => $strPtr->arrayType(self::MAX_BUCKETS),
            self::GLOBAL_BRIGADE_ACTIVE => $i8->arrayType(self::MAX_BRIGADES),
            self::GLOBAL_BRIGADE_COUNT => $i64->arrayType(self::MAX_BRIGADES),
            self::GLOBAL_BRIGADE_QUEUE => $i64->arrayType(self::MAX_BRIGADES * self::MAX_QUEUE),
        ] as $name => $ty) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $global = $context->module->addGlobal($ty, $name);
            $global->setInitializer($ty->constNull());
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        foreach ([
            ['__string__separate', $strPtr, [$strPtr]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emitBucketRegister(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_reg_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $max = $i64->constInt(self::MAX_BUCKETS, false);
        $base = $i64->constInt(self::BUCKET_HANDLE_BASE, false);
        $zeroI8 = $i8->constInt(0, false);
        $oneI8 = $i8->constInt(1, false);

        $slot = $context->builder->alloca($i64, 1, 'sb_slot');
        $context->builder->store($zeroI64, $slot);
        $loopHead = $fn->appendBasicBlock('sb_reg_loop');
        $loopBody = $fn->appendBasicBlock('sb_reg_body');
        $fail = $fn->appendBasicBlock('sb_reg_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($slot);
        $done = $context->builder->icmp(Builder::INT_SGE, $idx, $max);
        $context->builder->branchIf($done, $fail, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $active = self::loadBucketActive($context, $idx);
        $free = $context->builder->icmp(Builder::INT_EQ, $active, $zeroI8);
        $useBb = $fn->appendBasicBlock('sb_reg_use');
        $nextBb = $fn->appendBasicBlock('sb_reg_next');
        $context->builder->branchIf($free, $useBb, $nextBb);

        $context->builder->positionAtEnd($useBb);
        self::storeBucketActive($context, $idx, $oneI8);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        self::storeBucketData($context, $idx, $owned);
        $handle = $context->builder->add($base, $idx);
        $context->builder->returnValue($handle);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->add($idx, $oneI64), $slot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitBucketData(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_data_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $base = $i64->constInt(self::BUCKET_HANDLE_BASE, false);
        $max = $i64->constInt(self::MAX_BUCKETS, false);

        $slot = $context->builder->sub($handle, $base);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $failBb = $fn->appendBasicBlock('sb_data_fail');
        $okBb = $fn->appendBasicBlock('sb_data_ok');
        $context->builder->branchIf($bad, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(self::loadBucketData($context, $slot));
    }

    private static function emitIsBucketResource(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_is_bucket_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $base = $i64->constInt(self::BUCKET_HANDLE_BASE, false);
        $max = $i64->constInt(self::MAX_BUCKETS, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $slot = $context->builder->sub($handle, $base);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $falseBb = $fn->appendBasicBlock('sb_is_bucket_false');
        $checkBb = $fn->appendBasicBlock('sb_is_bucket_check');
        $context->builder->branchIf($bad, $falseBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $active = self::loadBucketActive($context, $slot);
        $ok = $context->builder->icmp(Builder::INT_NE, $active, $i8->constInt(0, false));
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitIsBrigadeResource(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_is_brig_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $base = $i64->constInt(self::BRIGADE_HANDLE_BASE, false);
        $max = $i64->constInt(self::MAX_BRIGADES, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $slot = $context->builder->sub($handle, $base);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $max)
        );
        $falseBb = $fn->appendBasicBlock('sb_is_brig_false');
        $checkBb = $fn->appendBasicBlock('sb_is_brig_check');
        $context->builder->branchIf($bad, $falseBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $active = self::loadBrigadeActive($context, $slot);
        $ok = $context->builder->icmp(Builder::INT_NE, $active, $i8->constInt(0, false));
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitBrigadeAlloc(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_brig_alloc_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $max = $i64->constInt(self::MAX_BRIGADES, false);
        $base = $i64->constInt(self::BRIGADE_HANDLE_BASE, false);
        $zeroI8 = $i8->constInt(0, false);
        $oneI8 = $i8->constInt(1, false);

        $slot = $context->builder->alloca($i64, 1, 'sb_brig_slot');
        $context->builder->store($zeroI64, $slot);
        $loopHead = $fn->appendBasicBlock('sb_brig_loop');
        $loopBody = $fn->appendBasicBlock('sb_brig_body');
        $fail = $fn->appendBasicBlock('sb_brig_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($slot);
        $done = $context->builder->icmp(Builder::INT_SGE, $idx, $max);
        $context->builder->branchIf($done, $fail, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $active = self::loadBrigadeActive($context, $idx);
        $free = $context->builder->icmp(Builder::INT_EQ, $active, $zeroI8);
        $useBb = $fn->appendBasicBlock('sb_brig_use');
        $nextBb = $fn->appendBasicBlock('sb_brig_next');
        $context->builder->branchIf($free, $useBb, $nextBb);

        $context->builder->positionAtEnd($useBb);
        self::storeBrigadeActive($context, $idx, $oneI8);
        self::storeBrigadeCount($context, $idx, $zeroI64);
        $context->builder->returnValue($context->builder->add($base, $idx));

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->add($idx, $oneI64), $slot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitBrigadePush(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_push_entry');
        $context->builder->positionAtEnd($entry);

        $brigadeHandle = $fn->getParam(0);
        $bucketHandle = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $base = $i64->constInt(self::BRIGADE_HANDLE_BASE, false);
        $maxBrig = $i64->constInt(self::MAX_BRIGADES, false);
        $maxQueue = $i64->constInt(self::MAX_QUEUE, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $slot = $context->builder->sub($brigadeHandle, $base);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $maxBrig)
        );
        $failBb = $fn->appendBasicBlock('sb_push_fail');
        $workBb = $fn->appendBasicBlock('sb_push_work');
        $context->builder->branchIf($bad, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $count = self::loadBrigadeCount($context, $slot);
        $full = $context->builder->icmp(Builder::INT_SGE, $count, $maxQueue);
        $fullBb = $fn->appendBasicBlock('sb_push_full');
        $storeBb = $fn->appendBasicBlock('sb_push_store');
        $context->builder->branchIf($full, $fullBb, $storeBb);

        $context->builder->positionAtEnd($storeBb);
        $queueIndex = $context->builder->add(
            $context->builder->mul($slot, $maxQueue),
            $count
        );
        self::storeQueueEntry($context, $queueIndex, $bucketHandle);
        self::storeBrigadeCount($context, $slot, $context->builder->add($count, $i64->constInt(1, false)));
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fullBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitBrigadePop(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_pop_entry');
        $context->builder->positionAtEnd($entry);

        $brigadeHandle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $base = $i64->constInt(self::BRIGADE_HANDLE_BASE, false);
        $maxBrig = $i64->constInt(self::MAX_BRIGADES, false);
        $maxQueue = $i64->constInt(self::MAX_QUEUE, false);
        $minusOne = $i64->constInt(-1, true);

        $slot = $context->builder->sub($brigadeHandle, $base);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $slot, $maxBrig)
        );
        $failBb = $fn->appendBasicBlock('sb_pop_fail');
        $workBb = $fn->appendBasicBlock('sb_pop_work');
        $context->builder->branchIf($bad, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $count = self::loadBrigadeCount($context, $slot);
        $empty = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('sb_pop_empty');
        $popBb = $fn->appendBasicBlock('sb_pop_pop');
        $context->builder->branchIf($empty, $emptyBb, $popBb);

        $context->builder->positionAtEnd($popBb);
        $headIndex = $context->builder->mul($slot, $maxQueue);
        $handle = self::loadQueueEntry($context, $headIndex);
        $newCount = $context->builder->sub($count, $i64->constInt(1, false));
        self::storeBrigadeCount($context, $slot, $newCount);

        $shiftHead = $fn->appendBasicBlock('sb_pop_shift');
        $doneShift = $fn->appendBasicBlock('sb_pop_done_shift');
        $context->builder->branch($shiftHead);

        $context->builder->positionAtEnd($shiftHead);
        $iSlot = $context->builder->alloca($i64, 1, 'sb_pop_i');
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $shiftLoop = $fn->appendBasicBlock('sb_pop_shift_loop');
        $shiftBody = $fn->appendBasicBlock('sb_pop_shift_body');
        $context->builder->branch($shiftLoop);

        $context->builder->positionAtEnd($shiftLoop);
        $i = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $i, $newCount);
        $context->builder->branchIf($stop, $doneShift, $shiftBody);

        $context->builder->positionAtEnd($shiftBody);
        $from = $context->builder->add($headIndex, $context->builder->add($i, $i64->constInt(1, false)));
        $to = $context->builder->add($headIndex, $i);
        self::storeQueueEntry($context, $to, self::loadQueueEntry($context, $from));
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($shiftLoop);

        $context->builder->positionAtEnd($doneShift);
        $context->builder->returnValue($handle);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitBucketObjectNew(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_obj_entry');
        $context->builder->positionAtEnd($entry);

        $bucketHandle = $fn->getParam(0);
        $dataStr = $fn->getParam(1);
        $objectType = $context->type->object;
        $classId = $objectType->lookup('StreamBucket');

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $bucketVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $bucketHandle
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'StreamBucket', 'bucket'),
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
            $objectType->propertySlotFor($obj, 'StreamBucket', 'data'),
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
        $context->builder->returnValue($ptr);
    }

    private static function loadBucketActive(Context $context, Value $slot): Value
    {
        return self::loadTableU8($context, self::GLOBAL_BUCKET_ACTIVE, $slot);
    }

    private static function storeBucketActive(Context $context, Value $slot, Value $value): void
    {
        self::storeTableU8($context, self::GLOBAL_BUCKET_ACTIVE, $slot, $value);
    }

    private static function loadBucketData(Context $context, Value $slot): Value
    {
        $global = $context->module->getNamedGlobal(self::GLOBAL_BUCKET_DATA);
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->load(
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $strPtr->pointerType(0)
            )
        );
    }

    private static function storeBucketData(Context $context, Value $slot, Value $value): void
    {
        $global = $context->module->getNamedGlobal(self::GLOBAL_BUCKET_DATA);
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $context->builder->store(
            $value,
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $strPtr->pointerType(0)
            )
        );
    }

    private static function loadBrigadeActive(Context $context, Value $slot): Value
    {
        return self::loadTableU8($context, self::GLOBAL_BRIGADE_ACTIVE, $slot);
    }

    private static function storeBrigadeActive(Context $context, Value $slot, Value $value): void
    {
        self::storeTableU8($context, self::GLOBAL_BRIGADE_ACTIVE, $slot, $value);
    }

    private static function loadBrigadeCount(Context $context, Value $slot): Value
    {
        return self::loadTableI64($context, self::GLOBAL_BRIGADE_COUNT, $slot);
    }

    private static function storeBrigadeCount(Context $context, Value $slot, Value $value): void
    {
        self::storeTableI64($context, self::GLOBAL_BRIGADE_COUNT, $slot, $value);
    }

    private static function loadQueueEntry(Context $context, Value $index): Value
    {
        return self::loadTableI64($context, self::GLOBAL_BRIGADE_QUEUE, $index);
    }

    private static function storeQueueEntry(Context $context, Value $index, Value $value): void
    {
        self::storeTableI64($context, self::GLOBAL_BRIGADE_QUEUE, $index, $value);
    }

    private static function loadTableU8(Context $context, string $globalName, Value $slot): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        $i8 = $context->getTypeFromString('int8');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->load(
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $i8->pointerType(0)
            )
        );
    }

    private static function storeTableU8(Context $context, string $globalName, Value $slot, Value $value): void
    {
        $global = $context->module->getNamedGlobal($globalName);
        $i8 = $context->getTypeFromString('int8');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $context->builder->store(
            $value,
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $i8->pointerType(0)
            )
        );
    }

    private static function loadTableI64(Context $context, string $globalName, Value $slot): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        return $context->builder->load(
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $i64->pointerType(0)
            )
        );
    }

    private static function storeTableI64(Context $context, string $globalName, Value $slot, Value $value): void
    {
        $global = $context->module->getNamedGlobal($globalName);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $context->builder->store(
            $value,
            $context->builder->bitcast(
                $context->builder->gep($global, $zero, $slot),
                $i64->pointerType(0)
            )
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
