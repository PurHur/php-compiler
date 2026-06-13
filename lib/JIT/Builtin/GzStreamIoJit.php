<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM gz* stream helpers — gzopen/gzwrite/gzread/gzclose (#6168).
 *
 * Thin libz ABI on shared phpc_stream_handles table; gz streams tagged phpc_stream_is_gz.
 * php-src: ext/zlib/zlib.c
 */
final class GzStreamIoJit
{
    private const MAX_HANDLES = StreamGlobalsJit::MAX_HANDLES;

    private const GLOBAL_IS_GZ = 'phpc_stream_is_gz';

    private const DEFAULT_CHUNK_SIZE = 8192;

    private const DEFAULT_BUFFER_SIZE = 8192;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gzopen',
        '__compiler_gzwrite',
        '__compiler_gzread',
        '__compiler_gzgets',
        '__compiler_gzclose',
        '__compiler_gz_read_all',
        '__compiler_gz_passthru',
    ];

    public static function implement(Context $context): void
    {
        StreamGlobalsJit::ensureGlobals($context);
        self::ensureIsGzGlobal($context);

        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibz($context);

        self::implementIfMissing($context, '__compiler_gzopen', self::emitGzopen(...));
        self::implementIfMissing($context, '__compiler_gzwrite', self::emitGzwrite(...));
        self::implementIfMissing($context, '__compiler_gzread', self::emitGzread(...));
        self::implementIfMissing($context, '__compiler_gzgets', self::emitGzgets(...));
        self::implementIfMissing($context, '__compiler_gzclose', self::emitGzclose(...));
        self::implementIfMissing($context, '__compiler_gz_read_all', self::emitGzReadAll(...));
        self::implementIfMissing($context, '__compiler_gz_passthru', self::emitGzPassthru(...));
    }

    private static function ensureIsGzGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::GLOBAL_IS_GZ)) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $context->module->addGlobal($i8->arrayType(self::MAX_HANDLES), self::GLOBAL_IS_GZ);
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
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

        $fn = match ($name) {
            '__compiler_gzopen' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $i64)
            ),
            '__compiler_gzwrite' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            ),
            '__compiler_gzread' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64)
            ),
            '__compiler_gzgets' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64)
            ),
            '__compiler_gzclose' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_gz_read_all' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64)
            ),
            '__compiler_gz_passthru' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64)
            ),
            default => throw new \LogicException('GzStreamIoJit: unknown '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibz(Context $context): void
    {
        StringZlib::preloadLibz();

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['__string__strlen', $i64, [$strPtr]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['malloc', $i8p, [$sizeT]],
            ['free', $void, [$i8p]],
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['gzopen', $i8p, [$i8p, $i8p]],
            ['gzwrite', $i32, [$i8p, $i8p, $i32]],
            ['gzread', $i32, [$i8p, $i8p, $i32]],
            ['gzclose', $i32, [$i8p]],
            ['fwrite', $context->getTypeFromString('size_t'), [$i8p, $context->getTypeFromString('size_t'), $context->getTypeFromString('size_t'), $i8p]],
            ['realloc', $i8p, [$i8p, $sizeT]],
            ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
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

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function loadPtrSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GzStreamIoJit: '.$globalName.' missing');
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
            throw new \LogicException('GzStreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeI32Slot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GzStreamIoJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeIsGz(Context $context, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_IS_GZ);
        if (null === $global) {
            throw new \LogicException('GzStreamIoJit: '.self::GLOBAL_IS_GZ.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($i8->constInt(1, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function clearIsGz(Context $context, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_IS_GZ);
        if (null === $global) {
            throw new \LogicException('GzStreamIoJit: '.self::GLOBAL_IS_GZ.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($i8->constInt(0, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function loadIsGz(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_IS_GZ);
        if (null === $global) {
            throw new \LogicException('GzStreamIoJit: '.self::GLOBAL_IS_GZ.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function emitGzopen(Context $context, LlvmFunction $fn): void
    {
        $prefix = 'gzopen';
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
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
            $context->builder->icmp(Builder::INT_EQ, $path, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $mode, $nullStr)
        );
        $context->builder->branchIf($badArgs, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call(
            $context->lookupFunction('gzopen'),
            self::stringData($context, $path),
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
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $idPhi = $context->builder->phi($i64, $prefix.'_id');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $exhaustBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $slotFp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock($prefix.'_alloc');
        $context->builder->branchIf($slotFree, $allocBb, $loopSkipBb);

        $context->builder->positionAtEnd($allocBb);
        self::storePtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $idPhi, $fp);
        self::storeI32Slot($context, StreamGlobalsJit::GLOBAL_CHUNK_SIZE, $idPhi, $defaultChunk);
        self::storeI32Slot($context, StreamGlobalsJit::GLOBAL_WRITE_BUFFER, $idPhi, $defaultBuf);
        self::storeI32Slot($context, StreamGlobalsJit::GLOBAL_READ_BUFFER, $idPhi, $defaultBuf);
        self::storePtrSlot($context, StreamGlobalsJit::GLOBAL_PATHS, $idPhi, $nullPtr);
        self::storeIsGz($context, $idPhi);
        $context->builder->returnValue($idPhi);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($exhaustBb);
        $context->builder->call($context->lookupFunction('gzclose'), $fp);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitGzwrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzwrite_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $data = $fn->getParam(1);
        $length = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();

        $failBb = $fn->appendBasicBlock('gzwrite_fail');
        $checkBb = $fn->appendBasicBlock('gzwrite_check');
        $badArgs = $context->builder->icmp(Builder::INT_EQ, $data, $nullStr);
        $context->builder->branchIf($badArgs, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $context->getTypeFromString('int8')->constInt(0, false));
        $loadBb = $fn->appendBasicBlock('gzwrite_load');
        $context->builder->branchIf($notGz, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $workBb = $fn->appendBasicBlock('gzwrite_work');
        $context->builder->branchIf($fpNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $dataLenI64 = $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $writeLen = $context->builder->trunc($dataLenI64, $i32);
        $lenNonNeg = $context->builder->icmp(Builder::INT_SGE, $length, $zeroI64);
        $lenLtData = $context->builder->icmp(Builder::INT_SLT, $length, $dataLenI64);
        $useLen = $context->builder->and($lenNonNeg, $lenLtData);
        $writeLen = $context->builder->select($useLen, $context->builder->trunc($length, $i32), $writeLen);
        $n = $context->builder->call(
            $context->lookupFunction('gzwrite'),
            $fp,
            self::stringData($context, $data),
            $writeLen
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $n, $i32->constInt(0, false));
        $okBb = $fn->appendBasicBlock('gzwrite_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($context->builder->sext($n, $i64));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitGzread(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzread_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $emptyCstr = $context->pointerFromStringConstant('');

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zeroI64);
        $failBb = $fn->appendBasicBlock('gzread_fail');
        $checkBb = $fn->appendBasicBlock('gzread_check');
        $context->builder->branchIf($negLen, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $context->getTypeFromString('int8')->constInt(0, false));
        $loadBb = $fn->appendBasicBlock('gzread_load');
        $context->builder->branchIf($notGz, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $zeroLenBb = $fn->appendBasicBlock('gzread_zero');
        $allocBb = $fn->appendBasicBlock('gzread_alloc');
        $context->builder->branchIf($fpNull, $failBb, $zeroLenBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $length, $zeroI64);
        $emptyBb = $fn->appendBasicBlock('gzread_empty');
        $context->builder->branchIf($isZero, $emptyBb, $allocBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $zeroI64, $emptyCstr)
        );

        $context->builder->positionAtEnd($allocBb);
        $readLen = $context->builder->trunc($length, $i32);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->sext($readLen, $context->getTypeFromString('size_t'))
        );
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('gzread_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $got = $context->builder->call(
            $context->lookupFunction('gzread'),
            $fp,
            $buf,
            $readLen
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $i32->constInt(0, false));
        $freeFailBb = $fn->appendBasicBlock('gzread_free_fail');
        $makeBb = $fn->appendBasicBlock('gzread_make');
        $context->builder->branchIf($gotZero, $freeFailBb, $makeBb);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($makeBb);
        $gotI64 = $context->builder->sext($got, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $gotI64,
            $buf
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitGzgets(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzgets_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $nl = $i8->constInt(10, false);

        $badLen = $context->builder->icmp(Builder::INT_SLE, $length, $zeroI64);
        $failBb = $fn->appendBasicBlock('gzgets_fail');
        $checkBb = $fn->appendBasicBlock('gzgets_check');
        $context->builder->branchIf($badLen, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $maxRead = $context->builder->sub($length, $oneI64);
        $maxZero = $context->builder->icmp(Builder::INT_SLE, $maxRead, $zeroI64);
        $isGzBb = $fn->appendBasicBlock('gzgets_is_gz');
        $context->builder->branchIf($maxZero, $failBb, $isGzBb);

        $context->builder->positionAtEnd($isGzBb);
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $i8->constInt(0, false));
        $loadBb = $fn->appendBasicBlock('gzgets_load');
        $context->builder->branchIf($notGz, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $allocBb = $fn->appendBasicBlock('gzgets_alloc');
        $context->builder->branchIf($fpNull, $failBb, $allocBb);

        $context->builder->positionAtEnd($allocBb);
        $readLen = $context->builder->trunc($maxRead, $i32);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->sext($readLen, $context->getTypeFromString('size_t'))
        );
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('gzgets_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $got = $context->builder->call(
            $context->lookupFunction('gzread'),
            $fp,
            $buf,
            $readLen
        );
        $gotNeg = $context->builder->icmp(Builder::INT_SLT, $got, $zeroI32);
        $freeFailBb = $fn->appendBasicBlock('gzgets_free_fail');
        $eofBb = $fn->appendBasicBlock('gzgets_eof');
        $context->builder->branchIf($gotNeg, $freeFailBb, $eofBb);

        $context->builder->positionAtEnd($eofBb);
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $zeroI32);
        $scanBb = $fn->appendBasicBlock('gzgets_scan');
        $context->builder->branchIf($gotZero, $freeFailBb, $scanBb);

        $context->builder->positionAtEnd($scanBb);
        $scanIdx = $context->builder->alloca($i64, 'gzgets_idx');
        $context->builder->store($zeroI64, $scanIdx);
        $outLen = $context->builder->alloca($i64, 'gzgets_out_len');
        $context->builder->store($context->builder->sext($got, $i64), $outLen);
        $scanLoopBb = $fn->appendBasicBlock('gzgets_scan_loop');
        $scanDoneBb = $fn->appendBasicBlock('gzgets_scan_done');
        $context->builder->branch($scanLoopBb);

        $context->builder->positionAtEnd($scanLoopBb);
        $idx = $context->builder->load($scanIdx);
        $gotI64 = $context->builder->sext($got, $i64);
        $idxEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $gotI64);
        $scanBodyBb = $fn->appendBasicBlock('gzgets_scan_body');
        $context->builder->branchIf($idxEnd, $scanDoneBb, $scanBodyBb);

        $context->builder->positionAtEnd($scanBodyBb);
        $chPtr = $context->builder->gep($buf, $context->builder->trunc($idx, $i32));
        $ch = $context->builder->load($chPtr);
        $isNl = $context->builder->icmp(Builder::INT_EQ, $ch, $nl);
        $scanIncBb = $fn->appendBasicBlock('gzgets_scan_inc');
        $nlFoundBb = $fn->appendBasicBlock('gzgets_nl_found');
        $context->builder->branchIf($isNl, $nlFoundBb, $scanIncBb);

        $context->builder->positionAtEnd($nlFoundBb);
        $context->builder->store($context->builder->add($idx, $oneI64), $outLen);
        $context->builder->branch($scanDoneBb);

        $context->builder->positionAtEnd($scanIncBb);
        $context->builder->store($context->builder->add($idx, $oneI64), $scanIdx);
        $context->builder->branch($scanLoopBb);

        $context->builder->positionAtEnd($scanDoneBb);
        $finalLen = $context->builder->load($outLen);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $buf
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitGzclose(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzclose_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zeroI64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('gzclose_fail');
        $checkBb = $fn->appendBasicBlock('gzclose_check');
        $context->builder->branchIf($badHandle, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $context->getTypeFromString('int8')->constInt(0, false));
        $loadBb = $fn->appendBasicBlock('gzclose_load');
        $context->builder->branchIf($notGz, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $noFp = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $workBb = $fn->appendBasicBlock('gzclose_work');
        $context->builder->branchIf($noFp, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        self::storePtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle, $nullPtr);
        self::storePtrSlot($context, StreamGlobalsJit::GLOBAL_PATHS, $handle, $nullPtr);
        self::clearIsGz($context, $handle);
        $rc = $context->builder->call($context->lookupFunction('gzclose'), $fp);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zeroI32);
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitGzReadAll(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gz_read_all_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $chunkI32 = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $emptyCstr = $context->pointerFromStringConstant('');

        $failBb = $fn->appendBasicBlock('gz_read_all_fail');
        $checkBb = $fn->appendBasicBlock('gz_read_all_check');
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $i8->constInt(0, false));
        $context->builder->branchIf($notGz, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $initBb = $fn->appendBasicBlock('gz_read_all_init');
        $context->builder->branchIf($fpNull, $failBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $chunk = $context->builder->alloca($i8, self::DEFAULT_CHUNK_SIZE, 'gz_read_all_chunk');
        $bufSlot = $context->builder->alloca($i8p, 1, 'gz_read_all_buf');
        $lenSlot = $context->builder->alloca($sizeT, 1, 'gz_read_all_len');
        $capSlot = $context->builder->alloca($sizeT, 1, 'gz_read_all_cap');
        $context->builder->store($nullPtr, $bufSlot);
        $context->builder->store($sizeT->constInt(0, false), $lenSlot);
        $context->builder->store($sizeT->constInt(0, false), $capSlot);
        $loopHead = $fn->appendBasicBlock('gz_read_all_head');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $got = $context->builder->call(
            $context->lookupFunction('gzread'),
            $fp,
            $chunk,
            $chunkI32
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $i32->constInt(0, false));
        $doneBb = $fn->appendBasicBlock('gz_read_all_done');
        $appendBb = $fn->appendBasicBlock('gz_read_all_append');
        $context->builder->branchIf($gotZero, $doneBb, $appendBb);

        $context->builder->positionAtEnd($appendBb);
        $lenNow = $context->builder->load($lenSlot);
        $capNow = $context->builder->load($capSlot);
        $gotSize = $context->builder->sext($got, $sizeT);
        $needCap = $context->builder->add($lenNow, $gotSize);
        $needsGrow = $context->builder->icmp(Builder::INT_ULE, $capNow, $needCap);
        $growBb = $fn->appendBasicBlock('gz_read_all_grow');
        $copyBb = $fn->appendBasicBlock('gz_read_all_copy');
        $context->builder->branchIf($needsGrow, $growBb, $copyBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $capNow, $sizeT->constInt(0, false)),
            $sizeT->constInt(self::DEFAULT_CHUNK_SIZE, false),
            $context->builder->mul($capNow, $sizeT->constInt(2, false))
        );
        $growCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $newCap, $needCap),
            $needCap,
            $newCap
        );
        $oldBuf = $context->builder->load($bufSlot);
        $newBuf = $context->builder->call($context->lookupFunction('realloc'), $oldBuf, $growCap);
        $growFail = $context->builder->icmp(Builder::INT_EQ, $newBuf, $nullPtr);
        $afterGrowBb = $fn->appendBasicBlock('gz_read_all_after_grow');
        $context->builder->branchIf($growFail, $failBb, $afterGrowBb);
        $context->builder->positionAtEnd($afterGrowBb);
        $context->builder->store($newBuf, $bufSlot);
        $context->builder->store($growCap, $capSlot);
        $context->builder->branch($copyBb);

        $context->builder->positionAtEnd($copyBb);
        $buf = $context->builder->load($bufSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->inBoundsGEP($buf, $lenNow),
            $chunk,
            $gotSize
        );
        $context->builder->store($context->builder->add($lenNow, $gotSize), $lenSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($doneBb);
        $finalLen = $context->builder->load($lenSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $finalLen, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('gz_read_all_empty');
        $makeBb = $fn->appendBasicBlock('gz_read_all_make');
        $context->builder->branchIf($isEmpty, $emptyBb, $makeBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $zeroI64, $emptyCstr)
        );

        $context->builder->positionAtEnd($makeBb);
        $finalBuf = $context->builder->load($bufSlot);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->sext($finalLen, $i64),
                $finalBuf
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitGzPassthru(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gz_passthru_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $nullPtr = $i8p->constNull();
        $oneSize = $sizeT->constInt(1, false);
        $bufCap = $sizeT->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $chunkI32 = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);

        $failBb = $fn->appendBasicBlock('gz_passthru_fail');
        $checkBb = $fn->appendBasicBlock('gz_passthru_check');
        $isGz = self::loadIsGz($context, $handle);
        $notGz = $context->builder->icmp(Builder::INT_EQ, $isGz, $i8->constInt(0, false));
        $context->builder->branchIf($notGz, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $fp = self::loadPtrSlot($context, StreamGlobalsJit::GLOBAL_HANDLES, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $initBb = $fn->appendBasicBlock('gz_passthru_init');
        $context->builder->branchIf($fpNull, $failBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $stdoutFp = $context->builder->call(
            $context->lookupFunction('__phpc_resolve_stream'),
            $i64->constInt(1, false)
        );
        $stdoutNull = $context->builder->icmp(Builder::INT_EQ, $stdoutFp, $nullPtr);
        $loopInitBb = $fn->appendBasicBlock('gz_passthru_loop_init');
        $context->builder->branchIf($stdoutNull, $failBb, $loopInitBb);

        $context->builder->positionAtEnd($loopInitBb);
        $buf = $context->builder->alloca($i8, self::DEFAULT_CHUNK_SIZE, 'gz_passthru_buf');
        $loopBb = $fn->appendBasicBlock('gz_passthru_loop');
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($loopBb);
        $totalPhi = $context->builder->phi($i64, 'gz_passthru_total');
        $totalPhi->addIncoming($zeroI64, $loopInitBb);
        $got = $context->builder->call(
            $context->lookupFunction('gzread'),
            $fp,
            $buf,
            $chunkI32
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $i32->constInt(0, false));
        $doneBb = $fn->appendBasicBlock('gz_passthru_done');
        $writeBb = $fn->appendBasicBlock('gz_passthru_write');
        $context->builder->branchIf($gotZero, $doneBb, $writeBb);

        $context->builder->positionAtEnd($writeBb);
        $gotSize = $context->builder->sext($got, $sizeT);
        $wrote = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $buf,
            $oneSize,
            $gotSize,
            $stdoutFp
        );
        $writeBad = $context->builder->icmp(Builder::INT_NE, $wrote, $gotSize);
        $nextBb = $fn->appendBasicBlock('gz_passthru_next');
        $context->builder->branchIf($writeBad, $failBb, $nextBb);

        $context->builder->positionAtEnd($nextBb);
        $nextTotal = $context->builder->add($totalPhi, $context->builder->sext($got, $i64));
        $totalPhi->addIncoming($nextTotal, $nextBb);
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($totalPhi);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }
}
