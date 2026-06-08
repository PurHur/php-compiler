<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream buffer controls — chunk size / timeout / setvbuf wrappers (#5343 phase 4).
 *
 * Replaces __compiler_stream_set_chunk_size / __compiler_stream_set_timeout /
 * __compiler_stream_set_write_buffer / __compiler_stream_set_read_buffer in phpc_stream.c.
 */
final class StreamBufferJit
{
    private const MAX_HANDLES = 256;

    private const DEFAULT_CHUNK_SIZE = 8192;

    private const DEFAULT_BUFFER_SIZE = 8192;

    private const IONBF = 2;

    private const IOFBF = 0;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_CHUNK_SIZE = 'phpc_stream_chunk_size';

    private const GLOBAL_WRITE_BUFFER = 'phpc_stream_write_buffer';

    private const GLOBAL_READ_BUFFER = 'phpc_stream_read_buffer';

    private const GLOBAL_WRITE_BUFFER_STORAGE = 'phpc_stream_write_buffer_storage';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_set_chunk_size',
        '__compiler_stream_set_timeout',
        '__compiler_stream_set_write_buffer',
        '__compiler_stream_set_read_buffer',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_set_chunk_size');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_stream_set_chunk_size', self::emitSetChunkSize(...));
        self::implementIfMissing($context, '__compiler_stream_set_timeout', self::emitSetTimeout(...));
        self::implementIfMissing($context, '__compiler_stream_set_write_buffer', self::emitSetWriteBuffer(...));
        self::implementIfMissing($context, '__compiler_stream_set_read_buffer', self::emitSetReadBuffer(...));
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
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = match ($name) {
            '__compiler_stream_set_timeout' => $context->context->functionType($i32, false, $i64, $i64, $i64),
            default => $context->context->functionType($i64, false, $i64, $i64),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['setvbuf', $i32, [$i8p, $i8p, $i32, $sizeT]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ptrTableTy = $i8p->arrayType(self::MAX_HANDLES);
        $i32TableTy = $i32->arrayType(self::MAX_HANDLES);
        $storageTy = $context->getTypeFromString('int8')->arrayType(8192)->arrayType(self::MAX_HANDLES);

        foreach ([
            self::GLOBAL_HANDLES => $ptrTableTy,
            self::GLOBAL_CHUNK_SIZE => $i32TableTy,
            self::GLOBAL_WRITE_BUFFER => $i32TableTy,
            self::GLOBAL_READ_BUFFER => $i32TableTy,
            self::GLOBAL_WRITE_BUFFER_STORAGE => $storageTy,
        ] as $name => $ty) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($ty, $name);
        }
    }

    private static function loadPtrSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamBufferJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function loadI32Slot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamBufferJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeI32Slot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamBufferJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storagePtrForHandle(Context $context, Value $handle): Value
    {
        $global = $context->module->getNamedGlobal(self::GLOBAL_WRITE_BUFFER_STORAGE);
        if (null === $global) {
            throw new \LogicException('StreamBufferJit: '.self::GLOBAL_WRITE_BUFFER_STORAGE.' missing');
        }
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $ptr = $context->builder->gep($global, $zero, $handle, $zero);

        return $context->builder->pointerCast($ptr, $context->getTypeFromString('int8*'));
    }

    private static function emitSetChunkSize(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('set_chunk_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $chunkSize = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero64 = $i64->constInt(0, false);
        $minusOne64 = $i64->constInt(-1, true);
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('set_chunk_fail');
        $checkOpenBb = $fn->appendBasicBlock('set_chunk_open');
        $context->builder->branchIf($badHandle, $failBb, $checkOpenBb);

        $context->builder->positionAtEnd($checkOpenBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $checkSizeBb = $fn->appendBasicBlock('set_chunk_check_size');
        $context->builder->branchIf($notOpen, $failBb, $checkSizeBb);

        $context->builder->positionAtEnd($checkSizeBb);
        $badSize = $context->builder->icmp(Builder::INT_SLE, $chunkSize, $zero64);
        $workBb = $fn->appendBasicBlock('set_chunk_work');
        $context->builder->branchIf($badSize, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $previous = self::loadI32Slot($context, self::GLOBAL_CHUNK_SIZE, $handle);
        $prevZero = $context->builder->icmp(Builder::INT_EQ, $previous, $i32->constInt(0, false));
        $prevValue = $context->builder->select(
            $prevZero,
            $i32->constInt(self::DEFAULT_CHUNK_SIZE, false),
            $previous
        );
        self::storeI32Slot($context, self::GLOBAL_CHUNK_SIZE, $handle, $context->builder->trunc($chunkSize, $i32));
        $context->builder->returnValue($context->builder->sext($prevValue, $i64));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne64);
    }

    private static function emitSetTimeout(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('set_timeout_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $seconds = $fn->getParam(1);
        $microseconds = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('set_timeout_fail');
        $checkOpenBb = $fn->appendBasicBlock('set_timeout_open');
        $context->builder->branchIf($badHandle, $failBb, $checkOpenBb);

        $context->builder->positionAtEnd($checkOpenBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $checkValuesBb = $fn->appendBasicBlock('set_timeout_values');
        $context->builder->branchIf($notOpen, $failBb, $checkValuesBb);

        $context->builder->positionAtEnd($checkValuesBb);
        $negSeconds = $context->builder->icmp(Builder::INT_SLT, $seconds, $zero64);
        $negMicros = $context->builder->icmp(Builder::INT_SLT, $microseconds, $zero64);
        $badValues = $context->builder->or($negSeconds, $negMicros);
        $okBb = $fn->appendBasicBlock('set_timeout_ok');
        $context->builder->branchIf($badValues, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero32);
    }

    private static function emitSetWriteBuffer(Context $context, LlvmFunction $fn): void
    {
        self::emitSetBuffer($context, $fn, self::GLOBAL_WRITE_BUFFER, 'set_write');
    }

    private static function emitSetReadBuffer(Context $context, LlvmFunction $fn): void
    {
        self::emitSetBuffer($context, $fn, self::GLOBAL_READ_BUFFER, 'set_read');
    }

    private static function emitSetBuffer(Context $context, LlvmFunction $fn, string $stateGlobal, string $prefix): void
    {
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $buffer = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero64 = $i64->constInt(0, false);
        $minusOne64 = $i64->constInt(-1, true);
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $checkOpenBb = $fn->appendBasicBlock($prefix.'_open');
        $context->builder->branchIf($badHandle, $failBb, $checkOpenBb);

        $context->builder->positionAtEnd($checkOpenBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $workBb = $fn->appendBasicBlock($prefix.'_work');
        $context->builder->branchIf($notOpen, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $previous = self::loadI32Slot($context, $stateGlobal, $handle);
        $prevZero = $context->builder->icmp(Builder::INT_EQ, $previous, $i32->constInt(0, false));
        $prevValue = $context->builder->select(
            $prevZero,
            $i32->constInt(self::DEFAULT_BUFFER_SIZE, false),
            $previous
        );

        $bufferEqZero = $context->builder->icmp(Builder::INT_EQ, $buffer, $zero64);
        $bufferLtZero = $context->builder->icmp(Builder::INT_SLT, $buffer, $zero64);
        $storageCap = $sizeT->constInt(self::DEFAULT_BUFFER_SIZE, false);
        $bufferAsSize = $context->builder->truncOrBitCast($buffer, $sizeT);
        $needsHeapBuf = $context->builder->icmp(Builder::INT_UGT, $bufferAsSize, $storageCap);

        $setIonbfBb = $fn->appendBasicBlock($prefix.'_ionbf');
        $setIofbfCheckBb = $fn->appendBasicBlock($prefix.'_iofbf_check');
        $setIofbfNegBb = $fn->appendBasicBlock($prefix.'_iofbf_neg');
        $selectStorageBb = $fn->appendBasicBlock($prefix.'_select_storage');
        $setIofbfStorageBb = $fn->appendBasicBlock($prefix.'_iofbf_storage');
        $setIofbfHeapBb = $fn->appendBasicBlock($prefix.'_iofbf_heap');
        $afterSetvbufBb = $fn->appendBasicBlock($prefix.'_after_setvbuf');
        $updateStateBb = $fn->appendBasicBlock($prefix.'_update_state');

        $context->builder->branchIf($bufferEqZero, $setIonbfBb, $setIofbfCheckBb);

        $context->builder->positionAtEnd($setIofbfCheckBb);
        $context->builder->branchIf($bufferLtZero, $setIofbfNegBb, $selectStorageBb);

        $context->builder->positionAtEnd($setIonbfBb);
        $rcIonbf = $context->builder->call(
            $context->lookupFunction('setvbuf'),
            $fp,
            $i8p->constNull(),
            $i32->constInt(self::IONBF, false),
            $sizeT->constInt(0, false)
        );
        $context->builder->branch($afterSetvbufBb);

        $context->builder->positionAtEnd($setIofbfNegBb);
        $rcDefault = $context->builder->call(
            $context->lookupFunction('setvbuf'),
            $fp,
            $i8p->constNull(),
            $i32->constInt(self::IOFBF, false),
            $sizeT->constInt(self::DEFAULT_BUFFER_SIZE, false)
        );
        $context->builder->branch($afterSetvbufBb);

        $context->builder->positionAtEnd($selectStorageBb);
        $context->builder->branchIf($needsHeapBuf, $setIofbfHeapBb, $setIofbfStorageBb);

        $context->builder->positionAtEnd($setIofbfHeapBb);
        $rcHeap = $context->builder->call(
            $context->lookupFunction('setvbuf'),
            $fp,
            $i8p->constNull(),
            $i32->constInt(self::IOFBF, false),
            $bufferAsSize
        );
        $context->builder->branch($afterSetvbufBb);

        $context->builder->positionAtEnd($setIofbfStorageBb);
        $storagePtr = self::storagePtrForHandle($context, $handle);
        $rcStorage = $context->builder->call(
            $context->lookupFunction('setvbuf'),
            $fp,
            $storagePtr,
            $i32->constInt(self::IOFBF, false),
            $bufferAsSize
        );
        $context->builder->branch($afterSetvbufBb);

        $context->builder->positionAtEnd($afterSetvbufBb);
        $rc = $context->builder->phi($i32, $prefix.'_setvbuf_rc');
        $rc->addIncoming($rcIonbf, $setIonbfBb);
        $rc->addIncoming($rcDefault, $setIofbfNegBb);
        $rc->addIncoming($rcHeap, $setIofbfHeapBb);
        $rc->addIncoming($rcStorage, $setIofbfStorageBb);
        $setvbufFailed = $context->builder->icmp(Builder::INT_NE, $rc, $i32->constInt(0, false));
        $context->builder->branchIf($setvbufFailed, $failBb, $updateStateBb);

        $context->builder->positionAtEnd($updateStateBb);
        $nextStateFromSign = $context->builder->select(
            $bufferLtZero,
            $i32->constInt(self::DEFAULT_BUFFER_SIZE, false),
            $context->builder->trunc($buffer, $i32)
        );
        $nextState = $context->builder->select($bufferEqZero, $i32->constInt(0, false), $nextStateFromSign);
        self::storeI32Slot($context, $stateGlobal, $handle, $nextState);
        $context->builder->returnValue($context->builder->sext($prevValue, $i64));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne64);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamBufferJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
