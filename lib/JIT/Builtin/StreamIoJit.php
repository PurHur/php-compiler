<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream I/O helpers — fopen/fread/fwrite/tmpfile (#5343 phase 3, #4436).
 *
 * Replaces __compiler_fopen / __compiler_fread / __compiler_fwrite / __compiler_tmpfile
 * Handle table globals + __phpc_resolve_stream: StreamGlobalsJit.php (#5343 phase 5).
 *
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamIoJit
{
    private const MAX_HANDLES = 256;

    private const DEFAULT_CHUNK_SIZE = 8192;

    private const DEFAULT_BUFFER_SIZE = 8192;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    private const GLOBAL_WAS_USED = 'phpc_stream_was_used';

    private const GLOBAL_IS_POPEN = 'phpc_stream_is_popen';

    private const GLOBAL_CHUNK_SIZE = 'phpc_stream_chunk_size';

    private const GLOBAL_WRITE_BUFFER = 'phpc_stream_write_buffer';

    private const GLOBAL_READ_BUFFER = 'phpc_stream_read_buffer';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_fwrite',
        '__compiler_fopen',
        '__compiler_popen',
        '__compiler_tmpfile',
        '__compiler_fread',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_fwrite', self::emitFwrite(...));
        self::implementIfMissing($context, '__compiler_fopen', self::emitFopen(...));
        self::implementIfMissing($context, '__compiler_popen', self::emitPopen(...));
        self::implementIfMissing($context, '__compiler_tmpfile', self::emitTmpfile(...));
        self::implementIfMissing($context, '__compiler_fread', self::emitFread(...));
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
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
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $fn = match ($name) {
            '__compiler_fwrite' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            ),
            '__compiler_fopen' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__compiler_popen' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__compiler_tmpfile' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false)
            ),
            '__compiler_fread' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64)
            ),
            default => throw new \LogicException('StreamIoJit: unknown '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['__string__strlen', $i64, [$strPtr]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['fwrite', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fopen', $i8p, [$i8p, $i8p]],
            ['popen', $i8p, [$i8p, $i8p]],
            ['pclose', $i32, [$i8p]],
            ['fclose', $i32, [$i8p]],
            ['tmpfile', $i8p, []],
            ['strdup', $i8p, [$i8p]],
            ['free', $void, [$i8p]],
            ['malloc', $i8p, [$sizeT]],
            ['fread', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['ferror', $i32, [$i8p]],
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
        $wasUsedTy = $context->getTypeFromString('int8')->arrayType(self::MAX_HANDLES);

        foreach ([
            self::GLOBAL_HANDLES => $ptrTableTy,
            self::GLOBAL_PATHS => $ptrTableTy,
            self::GLOBAL_CHUNK_SIZE => $i32TableTy,
            self::GLOBAL_WRITE_BUFFER => $i32TableTy,
            self::GLOBAL_READ_BUFFER => $i32TableTy,
            self::GLOBAL_WAS_USED => $wasUsedTy,
            self::GLOBAL_IS_POPEN => $wasUsedTy,
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
            throw new \LogicException('StreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storePtrSlot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function loadI32Slot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamIoJit: '.$globalName.' missing');
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
            throw new \LogicException('StreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeWasUsed(Context $context, Value $handle): void
    {
        self::storeI8Flag($context, self::GLOBAL_WAS_USED, $handle);
    }

    private static function storeIsPopen(Context $context, Value $handle): void
    {
        self::storeI8Flag($context, self::GLOBAL_IS_POPEN, $handle);
    }

    private static function storeI8Flag(Context $context, string $globalName, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zeroI64, $handle);
        $context->builder->store($i8->constInt(1, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->load($context->builder->structGep($str, $map['value'])),
            $context->getTypeFromString('int8*')
        );
    }

    private static function emitFwrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fwrite_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $data = $fn->getParam(1);
        $length = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $nullPtr = $i8p->constNull();
        $nullStr = $context->getTypeFromString('__string__*')->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $data, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle), $nullPtr)
        );
        $failBb = $fn->appendBasicBlock('fwrite_fail');
        $workBb = $fn->appendBasicBlock('fwrite_work');
        $context->builder->branchIf($badArgs, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $dataLenI64 = $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $dataLen = $context->builder->trunc($dataLenI64, $sizeT);
        $writeLen = $dataLen;
        $lenNonNeg = $context->builder->icmp(Builder::INT_SGE, $length, $zeroI64);
        $lenLtData = $context->builder->icmp(Builder::INT_SLT, $length, $dataLenI64);
        $useLen = $context->builder->and($lenNonNeg, $lenLtData);
        $writeLen = $context->builder->select($useLen, $context->builder->trunc($length, $sizeT), $writeLen);
        $zeroSize = $sizeT->constInt(0, false);
        $zeroLenBb = $fn->appendBasicBlock('fwrite_zero');
        $doWriteBb = $fn->appendBasicBlock('fwrite_do');
        $isZero = $context->builder->icmp(Builder::INT_EQ, $writeLen, $zeroSize);
        $context->builder->branchIf($isZero, $zeroLenBb, $doWriteBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $context->builder->returnValue($zeroI64);

        $context->builder->positionAtEnd($doWriteBb);
        $n = $context->builder->call(
            $context->lookupFunction('fwrite'),
            self::stringData($context, $data),
            $sizeT->constInt(1, false),
            $writeLen,
            $fp
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $n, $writeLen);
        $retBb = $fn->appendBasicBlock('fwrite_ret');
        $context->builder->branchIf($ok, $retBb, $failBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($context->builder->sext($n, $i64));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFopen(Context $context, LlvmFunction $fn): void
    {
        self::emitOpenHandle($context, $fn, withPath: true);
    }

    private static function emitPopen(Context $context, LlvmFunction $fn): void
    {
        $prefix = 'popen';
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $command = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $defaultBuf = $i32->constInt(self::DEFAULT_BUFFER_SIZE, false);

        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $openBb = $fn->appendBasicBlock($prefix.'_call');

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $command, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $mode, $nullStr)
        );
        $context->builder->branchIf($badArgs, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call(
            $context->lookupFunction('popen'),
            self::stringData($context, $command),
            self::stringData($context, $mode)
        );

        $loopInitBb = $fn->appendBasicBlock($prefix.'_loop_init');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $loopInitBb);

        $loopCheckBb = $fn->appendBasicBlock($prefix.'_loop_check');
        $loopBodyBb = $fn->appendBasicBlock($prefix.'_loop_body');
        $loopSkipBb = $fn->appendBasicBlock($prefix.'_loop_skip');
        $loopIncBb = $fn->appendBasicBlock($prefix.'_loop_inc');
        $exhaustBb = $fn->appendBasicBlock($prefix.'_exhaust');

        $context->builder->positionAtEnd($loopInitBb);
        $idPhi = $context->builder->phi($i64, $prefix.'_id');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $exhaustBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $slotFp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock($prefix.'_alloc');
        $context->builder->branchIf($slotFree, $allocBb, $loopSkipBb);

        $context->builder->positionAtEnd($allocBb);
        self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $fp);
        self::storeI32Slot($context, self::GLOBAL_CHUNK_SIZE, $idPhi, $defaultChunk);
        self::storeI32Slot($context, self::GLOBAL_WRITE_BUFFER, $idPhi, $defaultBuf);
        self::storeI32Slot($context, self::GLOBAL_READ_BUFFER, $idPhi, $defaultBuf);
        self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $nullPtr);
        self::storeWasUsed($context, $idPhi);
        self::storeIsPopen($context, $idPhi);
        $context->builder->returnValue($idPhi);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($exhaustBb);
        $context->builder->call($context->lookupFunction('pclose'), $fp);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitTmpfile(Context $context, LlvmFunction $fn): void
    {
        self::emitOpenHandle($context, $fn, withPath: false);
    }

    private static function emitOpenHandle(Context $context, LlvmFunction $fn, bool $withPath): void
    {
        $prefix = $withPath ? 'fopen' : 'tmpfile';
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $path = $withPath ? $fn->getParam(0) : null;
        $mode = $withPath ? $fn->getParam(1) : null;
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $defaultBuf = $i32->constInt(self::DEFAULT_BUFFER_SIZE, false);

        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $openBb = $fn->appendBasicBlock($prefix.'_call');

        if ($withPath) {
            $badArgs = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $path, $nullStr),
                $context->builder->icmp(Builder::INT_EQ, $mode, $nullStr)
            );
            $context->builder->branchIf($badArgs, $failBb, $openBb);

            $context->builder->positionAtEnd($openBb);
            $fp = $context->builder->call(
                $context->lookupFunction('fopen'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
        } else {
            $context->builder->branch($openBb);

            $context->builder->positionAtEnd($openBb);
            $fp = $context->builder->call($context->lookupFunction('tmpfile'));
        }

        $loopInitBb = $fn->appendBasicBlock($prefix.'_loop_init');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $loopInitBb);

        $loopCheckBb = $fn->appendBasicBlock($prefix.'_loop_check');
        $loopBodyBb = $fn->appendBasicBlock($prefix.'_loop_body');
        $loopSkipBb = $fn->appendBasicBlock($prefix.'_loop_skip');
        $loopIncBb = $fn->appendBasicBlock($prefix.'_loop_inc');
        $exhaustBb = $fn->appendBasicBlock($prefix.'_exhaust');

        $context->builder->positionAtEnd($loopInitBb);
        $idPhi = $context->builder->phi($i64, $prefix.'_id');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $exhaustBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $slotFp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock($prefix.'_alloc');
        $context->builder->branchIf($slotFree, $allocBb, $loopSkipBb);

        $context->builder->positionAtEnd($allocBb);
        self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $fp);
        self::storeI32Slot($context, self::GLOBAL_CHUNK_SIZE, $idPhi, $defaultChunk);
        self::storeI32Slot($context, self::GLOBAL_WRITE_BUFFER, $idPhi, $defaultBuf);
        self::storeI32Slot($context, self::GLOBAL_READ_BUFFER, $idPhi, $defaultBuf);

        if ($withPath) {
            $dupBb = $fn->appendBasicBlock($prefix.'_dup');
            $dupFailBb = $fn->appendBasicBlock($prefix.'_dup_fail');
            $doneBb = $fn->appendBasicBlock($prefix.'_done');
            $context->builder->branch($dupBb);

            $context->builder->positionAtEnd($dupBb);
            $dup = $context->builder->call($context->lookupFunction('strdup'), self::stringData($context, $path));
            $dupNull = $context->builder->icmp(Builder::INT_EQ, $dup, $nullPtr);
            $context->builder->branchIf($dupNull, $dupFailBb, $doneBb);

            $context->builder->positionAtEnd($dupFailBb);
            self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $nullPtr);
            $context->builder->call($context->lookupFunction('fclose'), $fp);
            $context->builder->returnValue($minusOne);

            $context->builder->positionAtEnd($doneBb);
            self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $dup);
        } else {
            self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $nullPtr);
        }

        self::storeWasUsed($context, $idPhi);
        $context->builder->returnValue($idPhi);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($exhaustBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFread(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fread_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $emptyCstr = $context->pointerFromStringConstant('');

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zeroI64);
        $failBb = $fn->appendBasicBlock('fread_fail');
        $checkFpBb = $fn->appendBasicBlock('fread_check_fp');
        $context->builder->branchIf($negLen, $failBb, $checkFpBb);

        $context->builder->positionAtEnd($checkFpBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $zeroLenBb = $fn->appendBasicBlock('fread_zero');
        $allocBb = $fn->appendBasicBlock('fread_alloc');
        $context->builder->branchIf($fpNull, $failBb, $zeroLenBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $length, $zeroI64);
        $emptyBb = $fn->appendBasicBlock('fread_empty');
        $context->builder->branchIf($isZero, $emptyBb, $allocBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $zeroI64, $emptyCstr)
        );

        $context->builder->positionAtEnd($allocBb);
        $readLen = $context->builder->trunc($length, $sizeT);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $readLen);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('fread_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $got = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $readLen,
            $fp
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $errBb = $fn->appendBasicBlock('fread_err_check');
        $makeBb = $fn->appendBasicBlock('fread_make');
        $context->builder->branchIf($gotZero, $errBb, $makeBb);

        $context->builder->positionAtEnd($errBb);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $fp), $zeroI32);
        $freeFailBb = $fn->appendBasicBlock('fread_free_fail');
        $context->builder->branchIf($hasErr, $freeFailBb, $makeBb);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($makeBb);
        $gotI64 = $context->builder->sext($got, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $gotI64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamIoJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
