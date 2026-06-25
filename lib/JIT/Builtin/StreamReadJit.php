<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream read helpers — flock/fpassthru/fseek/gets/get_contents (#5343 phase 4).
 *
 * Replaces __compiler_flock / __compiler_fpassthru / __compiler_ftruncate / __compiler_ftell /
 * __compiler_fgetc / __compiler_fgets / __compiler_stream_get_line / __compiler_fseek /
 * __compiler_stream_get_contents in phpc_stream.c.
 */
final class StreamReadJit
{
    /** PHP LOCK_* operands (ext/standard/flock.c). */
    private const PHP_LOCK_SH = 1;

    private const PHP_LOCK_EX = 2;

    private const PHP_LOCK_UN = 3;

    private const PHP_LOCK_NB = 4;

    /** Host flock(2) flags (Linux). */
    private const SYS_LOCK_SH = 1;

    private const SYS_LOCK_EX = 2;

    private const SYS_LOCK_NB = 4;

    private const SYS_LOCK_UN = 8;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_flock',
        '__compiler_fpassthru',
        '__compiler_ftruncate',
        '__compiler_ftell',
        '__compiler_fgetc',
        '__compiler_fgets',
        '__compiler_stream_get_line',
        '__compiler_fseek',
        '__compiler_stream_get_contents',
        '__compiler_stream_copy_to_stream',
        '__compiler_stream_copy_to_string',
        '__phpc_read_stream_bytes',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        StreamFilter::ensureLinked($context);
        ObOutputRuntime::ensureLinked($context);

        self::implementIfMissing($context, '__phpc_read_stream_bytes', self::emitReadStreamBytes(...));
        self::implementIfMissing($context, '__compiler_flock', self::emitFlock(...));
        self::implementIfMissing($context, '__compiler_fpassthru', self::emitFpassthru(...));
        self::implementIfMissing($context, '__compiler_ftruncate', self::emitFtruncate(...));
        self::implementIfMissing($context, '__compiler_ftell', self::emitFtell(...));
        self::implementIfMissing($context, '__compiler_fgetc', self::emitFgetc(...));
        self::implementIfMissing($context, '__compiler_fgets', self::emitFgets(...));
        self::implementIfMissing($context, '__compiler_stream_get_line', self::emitStreamGetLine(...));
        self::implementIfMissing($context, '__compiler_fseek', self::emitFseek(...));
        self::implementIfMissing($context, '__compiler_stream_get_contents', self::emitStreamGetContents(...));
        self::implementIfMissing($context, '__compiler_stream_copy_to_stream', self::emitStreamCopyToStream(...));
        self::implementIfMissing($context, '__compiler_stream_copy_to_string', self::emitStreamCopyToString(...));
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

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = match ($name) {
            '__compiler_flock' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_fpassthru' => $context->context->functionType($i64, false, $i64),
            '__compiler_ftruncate' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_ftell' => $context->context->functionType($i64, false, $i64),
            '__compiler_fgetc' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_fgets' => $context->context->functionType($strPtr, false, $i64, $i64),
            '__compiler_stream_get_line' => $context->context->functionType($strPtr, false, $i64, $i64, $strPtr),
            '__compiler_fseek' => $context->context->functionType($i64, false, $i64, $i64, $i64),
            '__compiler_stream_get_contents' => $context->context->functionType($strPtr, false, $i64, $i64, $i64),
            '__compiler_stream_copy_to_stream' => $context->context->functionType($i64, false, $i64, $i64, $i64, $i64),
            '__compiler_stream_copy_to_string' => $context->context->functionType($strPtr, false, $i64, $i64, $i64),
            '__phpc_read_stream_bytes' => $context->context->functionType($strPtr, false, $i8p, $i64),
            default => throw new \LogicException('StreamReadJit: unknown '.$name),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['flock', $i32, [$i32, $i32]],
            ['fileno', $i32, [$i8p]],
            ['fread', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fwrite', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['ferror', $i32, [$i8p]],
            ['fflush', $i32, [$i8p]],
            ['ftruncate', $i32, [$i32, $i64]],
            ['clearerr', $void, [$i8p]],
            ['ftell', $i64, [$i8p]],
            ['fgetc', $i32, [$i8p]],
            ['fgets', $i8p, [$i8p, $i32, $i8p]],
            ['feof', $i32, [$i8p]],
            ['malloc', $i8p, [$sizeT]],
            ['free', $void, [$i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['memcmp', $i32, [$i8p, $i8p, $sizeT]],
            ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
            ['realloc', $i8p, [$i8p, $sizeT]],
            ['fseek', $i32, [$i8p, $i64, $i32]],
            ['strcmp', $i32, [$i8p, $i8p]],
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['__string__strlen', $i64, [$strPtr]],
            ['__compiler_stream_filter_apply_read', $strPtr, [$i64, $strPtr]],
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

    private static function emitFlock(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('flock_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $operation = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('flock_fail');
        $fdBb = $fn->appendBasicBlock('flock_fd');
        $context->builder->branchIf($fpNull, $failBb, $fdBb);

        $context->builder->positionAtEnd($fdBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero32);
        $mapBb = $fn->appendBasicBlock('flock_map');
        $context->builder->branchIf($fdBad, $failBb, $mapBb);

        $context->builder->positionAtEnd($mapBb);
        $op32 = $context->builder->trunc($operation, $i32);
        $phpUn = $i32->constInt(self::PHP_LOCK_UN, false);
        $hasUn = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($op32, $phpUn),
            $phpUn
        );
        $opWithoutUn = $context->builder->select(
            $hasUn,
            $context->builder->and($op32, $context->builder->xor($phpUn, $i32->constInt(-1, true))),
            $op32
        );
        $sysOp = $context->builder->or(
            $context->builder->select(
                $hasUn,
                $i32->constInt(self::SYS_LOCK_UN, false),
                $zero32
            ),
            $context->builder->or(
                $context->builder->select(
                    $context->builder->icmp(
                        Builder::INT_NE,
                        $context->builder->and($opWithoutUn, $i32->constInt(self::PHP_LOCK_SH, false)),
                        $zero32
                    ),
                    $i32->constInt(self::SYS_LOCK_SH, false),
                    $zero32
                ),
                $context->builder->or(
                    $context->builder->select(
                        $context->builder->icmp(
                            Builder::INT_NE,
                            $context->builder->and($opWithoutUn, $i32->constInt(self::PHP_LOCK_EX, false)),
                            $zero32
                        ),
                        $i32->constInt(self::SYS_LOCK_EX, false),
                        $zero32
                    ),
                    $context->builder->select(
                        $context->builder->icmp(
                            Builder::INT_NE,
                            $context->builder->and($opWithoutUn, $i32->constInt(self::PHP_LOCK_NB, false)),
                            $zero32
                        ),
                        $i32->constInt(self::SYS_LOCK_NB, false),
                        $zero32
                    )
                )
            )
        );
        $noOp = $context->builder->icmp(Builder::INT_EQ, $sysOp, $zero32);
        $callBb = $fn->appendBasicBlock('flock_call');
        $context->builder->branchIf($noOp, $failBb, $callBb);

        $context->builder->positionAtEnd($callBb);
        $rc = $context->builder->call($context->lookupFunction('flock'), $fd, $sysOp);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero32);
        $context->builder->returnValue($context->builder->select($ok, $one32, $zero32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero32);
    }

    private static function emitFpassthru(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fpassthru_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $bufCap = $sizeT->constInt(8192, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('fpassthru_fail');
        $loopInitBb = $fn->appendBasicBlock('fpassthru_loop_init');
        $context->builder->branchIf($fpNull, $failBb, $loopInitBb);

        $context->builder->positionAtEnd($loopInitBb);
        $buf = $context->builder->alloca($i8, 8192, 'fpassthru_buf');
        $loopBb = $fn->appendBasicBlock('fpassthru_loop');
        $context->builder->branch($loopBb);
        $context->builder->positionAtEnd($loopBb);
        $totalPhi = $context->builder->phi($i64, 'fpassthru_total');
        $totalPhi->addIncoming($zero64, $loopInitBb);
        $got = $context->builder->call($context->lookupFunction('fread'), $buf, $oneSize, $bufCap, $fp);
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $zeroCheckBb = $fn->appendBasicBlock('fpassthru_zero_check');
        $writeBb = $fn->appendBasicBlock('fpassthru_write');
        $context->builder->branchIf($gotZero, $zeroCheckBb, $writeBb);

        $context->builder->positionAtEnd($zeroCheckBb);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $fp), $zero32);
        $doneBb = $fn->appendBasicBlock('fpassthru_done');
        $context->builder->branchIf($hasErr, $failBb, $doneBb);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $buf,
            $got
        );
        $loopNextBb = $fn->appendBasicBlock('fpassthru_next');
        $context->builder->branch($loopNextBb);

        $context->builder->positionAtEnd($loopNextBb);
        $nextTotal = $context->builder->add($totalPhi, $context->builder->sext($got, $i64));
        $totalPhi->addIncoming($nextTotal, $loopNextBb);
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($totalPhi);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFtruncate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ftruncate_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $size = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero32 = $i32->constInt(0, false);
        $one32 = $i32->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('ftruncate_fail');
        $flushBb = $fn->appendBasicBlock('ftruncate_flush');
        $context->builder->branchIf($fpNull, $failBb, $flushBb);

        $context->builder->positionAtEnd($flushBb);
        $flushRc = $context->builder->call($context->lookupFunction('fflush'), $fp);
        $flushBad = $context->builder->icmp(Builder::INT_NE, $flushRc, $zero32);
        $fdBb = $fn->appendBasicBlock('ftruncate_fd');
        $context->builder->branchIf($flushBad, $failBb, $fdBb);

        $context->builder->positionAtEnd($fdBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero32);
        $doBb = $fn->appendBasicBlock('ftruncate_do');
        $context->builder->branchIf($fdBad, $failBb, $doBb);

        $context->builder->positionAtEnd($doBb);
        $rc = $context->builder->call($context->lookupFunction('ftruncate'), $fd, $size);
        $bad = $context->builder->icmp(Builder::INT_NE, $rc, $zero32);
        $okBb = $fn->appendBasicBlock('ftruncate_ok');
        $context->builder->branchIf($bad, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($context->lookupFunction('clearerr'), $fp);
        $context->builder->returnValue($one32);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero32);
    }

    private static function emitFtell(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ftell_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $seekEnd = $i32->constInt(\SEEK_END, false);
        $seekSet = $i32->constInt(\SEEK_SET, false);
        $nullPtr = $i8p->constNull();

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $failBb = $fn->appendBasicBlock('ftell_fail');
        $workBb = $fn->appendBasicBlock('ftell_work');
        $context->builder->branchIf($fpNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $pos = $context->builder->call($context->lookupFunction('ftell'), $fp);
        $bad = $context->builder->icmp(Builder::INT_SLT, $pos, $zero);
        $okBb = $fn->appendBasicBlock('ftell_ok');
        $context->builder->branchIf($bad, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $pathGlobal = $context->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_PATHS);
        $pathSlot = $context->builder->gep($pathGlobal, $zero, $handle);
        $pathPtr = $context->builder->load($context->builder->bitcast($pathSlot, $i8p->pointerType(0)));
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $pathPtr, $nullPtr);
        $plainBb = $fn->appendBasicBlock('ftell_plain');
        $memCheckBb = $fn->appendBasicBlock('ftell_mem_check');
        $context->builder->branchIf($pathNull, $plainBb, $memCheckBb);

        $context->builder->positionAtEnd($memCheckBb);
        $isMemory = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $pathPtr,
            $context->pointerFromStringConstant('php://memory')
        );
        $memoryMatch = $context->builder->icmp(Builder::INT_EQ, $isMemory, $zero32);
        $memBoundsBb = $fn->appendBasicBlock('ftell_mem_bounds');
        $tempCheckBb = $fn->appendBasicBlock('ftell_temp_check');
        $context->builder->branchIf($memoryMatch, $memBoundsBb, $tempCheckBb);

        $context->builder->positionAtEnd($tempCheckBb);
        $isTemp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $pathPtr,
            $context->pointerFromStringConstant('php://temp'),
            $sizeT->constInt(10, false)
        );
        $tempMatch = $context->builder->icmp(Builder::INT_EQ, $isTemp, $zero32);
        $context->builder->branchIf($tempMatch, $memBoundsBb, $plainBb);

        $context->builder->positionAtEnd($memBoundsBb);
        $context->builder->call($context->lookupFunction('fseek'), $fp, $zero, $seekEnd);
        $endPos = $context->builder->call($context->lookupFunction('ftell'), $fp);
        $context->builder->call($context->lookupFunction('fseek'), $fp, $pos, $seekSet);
        $pastEnd = $context->builder->icmp(Builder::INT_SGT, $pos, $endPos);
        $context->builder->branchIf($pastEnd, $failBb, $plainBb);

        $context->builder->positionAtEnd($plainBb);
        $context->builder->returnValue($pos);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFgetc(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fgetc_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('fgetc_fail');
        $readBb = $fn->appendBasicBlock('fgetc_read');
        $context->builder->branchIf($fpNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $c = $context->builder->call($context->lookupFunction('fgetc'), $fp);
        $isEof = $context->builder->icmp(Builder::INT_EQ, $c, $i32->constInt(-1, true));
        $eofBb = $fn->appendBasicBlock('fgetc_eof');
        $okBb = $fn->appendBasicBlock('fgetc_ok');
        $context->builder->branchIf($isEof, $eofBb, $okBb);

        $context->builder->positionAtEnd($eofBb);
        $atEof = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('feof'), $fp), $i32->constInt(0, false));
        $context->builder->branchIf($atEof, $failBb, $failBb);

        $context->builder->positionAtEnd($okBb);
        $buf = $context->builder->alloca($i8, 2, 'fgetc_buf');
        $context->builder->store($context->builder->trunc($c, $i8), $buf);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $i64->constInt(1, false)));
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $i64->constInt(1, false), $buf)
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitFgets(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fgets_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zero64 = $i64->constInt(0, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('fgets_fail');
        $checkLenBb = $fn->appendBasicBlock('fgets_check_len');
        $context->builder->branchIf($fpNull, $failBb, $checkLenBb);

        $context->builder->positionAtEnd($checkLenBb);
        $lenZero = $context->builder->icmp(Builder::INT_EQ, $length, $zero64);
        $allocBb = $fn->appendBasicBlock('fgets_alloc');
        $context->builder->branchIf($lenZero, $failBb, $allocBb);

        $context->builder->positionAtEnd($allocBb);
        $bufSize = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $length, $zero64),
            $sizeT->constInt(8192, false),
            $context->builder->truncOrBitCast($length, $sizeT)
        );
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufSize);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $readBb = $fn->appendBasicBlock('fgets_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $line = $context->builder->call(
            $context->lookupFunction('fgets'),
            $buf,
            $context->builder->truncOrBitCast($bufSize, $i32),
            $fp
        );
        $lineNull = $context->builder->icmp(Builder::INT_EQ, $line, $i8p->constNull());
        $makeBb = $fn->appendBasicBlock('fgets_make');
        $context->builder->branchIf($lineNull, $failBb, $makeBb);

        $context->builder->positionAtEnd($makeBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $buf);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $context->builder->sext($len, $i64), $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitStreamGetLine(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgl_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $maxLength = $fn->getParam(1);
        $ending = $fn->getParam(2);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zero64 = $i64->constInt(0, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('sgl_fail');
        $checkMaxBb = $fn->appendBasicBlock('sgl_check_max');
        $context->builder->branchIf($fpNull, $failBb, $checkMaxBb);

        $context->builder->positionAtEnd($checkMaxBb);
        $negMax = $context->builder->icmp(Builder::INT_SLT, $maxLength, $zero64);
        $normMaxBb = $fn->appendBasicBlock('sgl_norm_max');
        $context->builder->branchIf($negMax, $failBb, $normMaxBb);

        $context->builder->positionAtEnd($normMaxBb);
        $maxNorm = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $maxLength, $zero64),
            $i64->constInt(8192, false),
            $maxLength
        );
        $endingNull = $context->builder->icmp(Builder::INT_EQ, $ending, $nullStr);
        $endingLen = $context->builder->select(
            $endingNull,
            $i64->constInt(0, false),
            $context->builder->call($context->lookupFunction('__string__strlen'), $ending)
        );
        $endingEmpty = $context->builder->icmp(Builder::INT_EQ, $endingLen, $i64->constInt(0, false));
        $simplePath = $context->builder->or($endingNull, $endingEmpty);
        $simpleBb = $fn->appendBasicBlock('sgl_simple');
        $endingBb = $fn->appendBasicBlock('sgl_ending');
        $context->builder->branchIf($simplePath, $simpleBb, $endingBb);

        $context->builder->positionAtEnd($simpleBb);
        $simpleBuf = $context->builder->call($context->lookupFunction('malloc'), $context->builder->truncOrBitCast($maxNorm, $sizeT));
        $simpleBufNull = $context->builder->icmp(Builder::INT_EQ, $simpleBuf, $i8p->constNull());
        $simpleReadBb = $fn->appendBasicBlock('sgl_simple_read');
        $context->builder->branchIf($simpleBufNull, $failBb, $simpleReadBb);

        $context->builder->positionAtEnd($simpleReadBb);
        $got = $context->builder->call(
            $context->lookupFunction('fread'),
            $simpleBuf,
            $sizeT->constInt(1, false),
            $context->builder->truncOrBitCast($maxNorm, $sizeT),
            $fp
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $simpleZeroBb = $fn->appendBasicBlock('sgl_simple_zero');
        $simpleMakeBb = $fn->appendBasicBlock('sgl_simple_make');
        $context->builder->branchIf($gotZero, $simpleZeroBb, $simpleMakeBb);

        $context->builder->positionAtEnd($simpleZeroBb);
        $atEof = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('feof'), $fp), $i32->constInt(0, false));
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $fp), $i32->constInt(0, false));
        $isBad = $context->builder->or($atEof, $hasErr);
        $context->builder->branchIf($isBad, $failBb, $simpleMakeBb);

        $context->builder->positionAtEnd($simpleMakeBb);
        $resultSimple = $context->builder->call($context->lookupFunction('__string__init'), $context->builder->sext($got, $i64), $simpleBuf);
        $context->builder->call($context->lookupFunction('free'), $simpleBuf);
        $context->builder->returnValue($resultSimple);

        $context->builder->positionAtEnd($endingBb);
        $bufSlot = $context->builder->alloca($i8p, 1, 'sgl_buf');
        $lenSlot = $context->builder->alloca($sizeT, 1, 'sgl_len');
        $capSlot = $context->builder->alloca($sizeT, 1, 'sgl_cap');
        $initCap = $sizeT->constInt(64, false);
        $bufInit = $context->builder->call($context->lookupFunction('malloc'), $initCap);
        $bufInitNull = $context->builder->icmp(Builder::INT_EQ, $bufInit, $i8p->constNull());
        $loopBb = $fn->appendBasicBlock('sgl_loop');
        $context->builder->branchIf($bufInitNull, $failBb, $loopBb);

        $context->builder->positionAtEnd($loopBb);
        $context->builder->store($bufInit, $bufSlot);
        $context->builder->store($sizeT->constInt(0, false), $lenSlot);
        $context->builder->store($initCap, $capSlot);
        $headBb = $fn->appendBasicBlock('sgl_head');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $len = $context->builder->load($lenSlot);
        $limitHit = $context->builder->icmp(Builder::INT_SGE, $context->builder->sext($len, $i64), $maxNorm);
        $doneBb = $fn->appendBasicBlock('sgl_done');
        $readCharBb = $fn->appendBasicBlock('sgl_read_char');
        $context->builder->branchIf($limitHit, $doneBb, $readCharBb);

        $context->builder->positionAtEnd($readCharBb);
        $c = $context->builder->call($context->lookupFunction('fgetc'), $fp);
        $isEof = $context->builder->icmp(Builder::INT_EQ, $c, $i32->constInt(-1, true));
        $eofBb = $fn->appendBasicBlock('sgl_eof');
        $appendBb = $fn->appendBasicBlock('sgl_append');
        $context->builder->branchIf($isEof, $eofBb, $appendBb);

        $context->builder->positionAtEnd($eofBb);
        $lenNow = $context->builder->load($lenSlot);
        $lenZeroNow = $context->builder->icmp(Builder::INT_EQ, $lenNow, $sizeT->constInt(0, false));
        $atEofNow = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('feof'), $fp), $i32->constInt(0, false));
        $eofNull = $context->builder->and($lenZeroNow, $atEofNow);
        $context->builder->branchIf($eofNull, $failBb, $doneBb);

        $context->builder->positionAtEnd($appendBb);
        $lenBefore = $context->builder->load($lenSlot);
        $capBefore = $context->builder->load($capSlot);
        $needGrow = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->add($lenBefore, $sizeT->constInt(1, false)),
            $capBefore
        );
        $growBb = $fn->appendBasicBlock('sgl_grow');
        $appendNowBb = $fn->appendBasicBlock('sgl_append_now');
        $context->builder->branchIf($needGrow, $growBb, $appendNowBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $capBefore, $sizeT->constInt(64, false)),
            $sizeT->constInt(64, false),
            $context->builder->mul($capBefore, $sizeT->constInt(2, false))
        );
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($context->builder->load($bufSlot)),
            $newCap
        );
        $growNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $growOkBb = $fn->appendBasicBlock('sgl_grow_ok');
        $context->builder->branchIf($growNull, $failBb, $growOkBb);

        $context->builder->positionAtEnd($growOkBb);
        $context->builder->store($grown, $bufSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($appendNowBb);

        $context->builder->positionAtEnd($appendNowBb);
        $newBuf = $context->builder->load($bufSlot);
        $lenWrite = $context->builder->load($lenSlot);
        $context->builder->store($context->builder->trunc($c, $i8), $context->builder->gep($newBuf, $lenWrite));
        $lenAfter = $context->builder->add($lenWrite, $sizeT->constInt(1, false));
        $context->builder->store($lenAfter, $lenSlot);

        $endingData = self::stringData($context, $ending);
        $enoughForEnding = $context->builder->icmp(
            Builder::INT_UGE,
            $lenAfter,
            $context->builder->truncOrBitCast($endingLen, $sizeT)
        );
        $checkEndingBb = $fn->appendBasicBlock('sgl_check_ending');
        $loopBackBb = $fn->appendBasicBlock('sgl_loop_back');
        $context->builder->branchIf($enoughForEnding, $checkEndingBb, $loopBackBb);

        $context->builder->positionAtEnd($checkEndingBb);
        $start = $context->builder->sub($lenAfter, $context->builder->truncOrBitCast($endingLen, $sizeT));
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->bytePtr($context->builder->gep($newBuf, $start)),
            $context->bytePtr($endingData),
            $context->builder->truncOrBitCast($endingLen, $sizeT)
        );
        $hasEnding = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $stripEndingBb = $fn->appendBasicBlock('sgl_strip_ending');
        $context->builder->branchIf($hasEnding, $stripEndingBb, $loopBackBb);

        $context->builder->positionAtEnd($stripEndingBb);
        $context->builder->store($start, $lenSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopBackBb);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
        $finalBuf = $context->builder->load($bufSlot);
        $finalLen = $context->builder->load($lenSlot);
        $lenZeroFinal = $context->builder->icmp(Builder::INT_EQ, $finalLen, $sizeT->constInt(0, false));
        $atEofFinal = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('feof'), $fp), $i32->constInt(0, false));
        $returnNull = $context->builder->and($lenZeroFinal, $atEofFinal);
        $makeBb = $fn->appendBasicBlock('sgl_make');
        $context->builder->branchIf($returnNull, $failBb, $makeBb);

        $context->builder->positionAtEnd($makeBb);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $context->builder->sext($finalLen, $i64), $finalBuf);
        $context->builder->call($context->lookupFunction('free'), $finalBuf);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitFseek(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fseek_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $offset = $fn->getParam(1);
        $whence = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $minusOne = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('fseek_fail');
        $workBb = $fn->appendBasicBlock('fseek_work');
        $context->builder->branchIf($fpNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $rc = $context->builder->call($context->lookupFunction('fseek'), $fp, $offset, $context->builder->trunc($whence, $i32));
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $context->builder->returnValue($context->builder->select($ok, $zero, $minusOne));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitReadStreamBytes(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('read_bytes_entry');
        $context->builder->positionAtEnd($entry);

        $fp = $fn->getParam(0);
        $maxlength = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zero64 = $i64->constInt(0, false);

        $chunk = $context->builder->alloca($i8, 4096, 'read_bytes_chunk');
        $bufSlot = $context->builder->alloca($i8p, 1, 'read_bytes_buf');
        $lenSlot = $context->builder->alloca($sizeT, 1, 'read_bytes_len');
        $capSlot = $context->builder->alloca($sizeT, 1, 'read_bytes_cap');
        $newCapSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($i8p->constNull(), $bufSlot);
        $context->builder->store($sizeT->constInt(0, false), $lenSlot);
        $context->builder->store($sizeT->constInt(0, false), $capSlot);
        $headBb = $fn->appendBasicBlock('read_bytes_head');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $lenNow = $context->builder->load($lenSlot);
        $limitUnlimited = $context->builder->icmp(Builder::INT_SLT, $maxlength, $zero64);
        $limitNotReached = $context->builder->icmp(Builder::INT_SLT, $context->builder->sext($lenNow, $i64), $maxlength);
        $cont = $context->builder->or($limitUnlimited, $limitNotReached);
        $doneBb = $fn->appendBasicBlock('read_bytes_done');
        $computeBb = $fn->appendBasicBlock('read_bytes_compute');
        $context->builder->branchIf($cont, $computeBb, $doneBb);

        $context->builder->positionAtEnd($computeBb);
        $toReadSlot = $context->builder->alloca($sizeT, 1, 'read_bytes_to_read');
        $context->builder->store($sizeT->constInt(4096, false), $toReadSlot);
        $needBound = $context->builder->icmp(Builder::INT_SGE, $maxlength, $zero64);
        $boundBb = $fn->appendBasicBlock('read_bytes_bound');
        $readBb = $fn->appendBasicBlock('read_bytes_read');
        $context->builder->branchIf($needBound, $boundBb, $readBb);

        $context->builder->positionAtEnd($boundBb);
        $remaining = $context->builder->sub($maxlength, $context->builder->sext($lenNow, $i64));
        $remDone = $context->builder->icmp(Builder::INT_SLE, $remaining, $zero64);
        $setReadBb = $fn->appendBasicBlock('read_bytes_set_read');
        $context->builder->branchIf($remDone, $doneBb, $setReadBb);

        $context->builder->positionAtEnd($setReadBb);
        $remainingSize = $context->builder->truncOrBitCast($remaining, $sizeT);
        $smaller = $context->builder->icmp(Builder::INT_ULT, $remainingSize, $sizeT->constInt(4096, false));
        $toRead = $context->builder->select($smaller, $remainingSize, $sizeT->constInt(4096, false));
        $context->builder->store($toRead, $toReadSlot);
        $context->builder->branch($readBb);

        $context->builder->positionAtEnd($readBb);
        $toReadNow = $context->builder->load($toReadSlot);
        $got = $context->builder->call($context->lookupFunction('fread'), $chunk, $sizeT->constInt(1, false), $toReadNow, $fp);
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $gotZeroBb = $fn->appendBasicBlock('read_bytes_got_zero');
        $growCheckBb = $fn->appendBasicBlock('read_bytes_grow_check');
        $context->builder->branchIf($gotZero, $gotZeroBb, $growCheckBb);

        $context->builder->positionAtEnd($gotZeroBb);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $fp), $i32->constInt(0, false));
        $errBb = $fn->appendBasicBlock('read_bytes_err');
        $context->builder->branchIf($hasErr, $errBb, $doneBb);

        $context->builder->positionAtEnd($errBb);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($bufSlot));
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($growCheckBb);
        $lenBefore = $context->builder->load($lenSlot);
        $capBefore = $context->builder->load($capSlot);
        $need = $context->builder->add($context->builder->add($lenBefore, $got), $sizeT->constInt(1, false));
        $needsGrow = $context->builder->icmp(Builder::INT_UGT, $need, $capBefore);
        $growBb = $fn->appendBasicBlock('read_bytes_grow');
        $copyBb = $fn->appendBasicBlock('read_bytes_copy');
        $context->builder->branchIf($needsGrow, $growBb, $copyBb);

        $context->builder->positionAtEnd($growBb);
        $startCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $capBefore, $sizeT->constInt(4096, false)),
            $sizeT->constInt(4096, false),
            $context->builder->mul($capBefore, $sizeT->constInt(2, false))
        );
        $context->builder->store($startCap, $newCapSlot);
        $growLoopBb = $fn->appendBasicBlock('read_bytes_grow_loop');
        $context->builder->branch($growLoopBb);
        $context->builder->positionAtEnd($growLoopBb);
        $capIter = $context->builder->load($newCapSlot);
        $stillSmall = $context->builder->icmp(Builder::INT_UGT, $need, $capIter);
        $growIterBb = $fn->appendBasicBlock('read_bytes_grow_iter');
        $growDoneBb = $fn->appendBasicBlock('read_bytes_grow_done');
        $context->builder->branchIf($stillSmall, $growIterBb, $growDoneBb);

        $context->builder->positionAtEnd($growIterBb);
        $context->builder->store($context->builder->mul($capIter, $sizeT->constInt(2, false)), $newCapSlot);
        $context->builder->branch($growLoopBb);

        $context->builder->positionAtEnd($growDoneBb);
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($context->builder->load($bufSlot)),
            $context->builder->load($newCapSlot)
        );
        $growNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $growOkBb = $fn->appendBasicBlock('read_bytes_grow_ok');
        $context->builder->branchIf($growNull, $errBb, $growOkBb);

        $context->builder->positionAtEnd($growOkBb);
        $context->builder->store($grown, $bufSlot);
        $context->builder->store($context->builder->load($newCapSlot), $capSlot);
        $context->builder->branch($copyBb);

        $context->builder->positionAtEnd($copyBb);
        $bufNow = $context->builder->load($bufSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($context->builder->gep($bufNow, $lenBefore)),
            $context->bytePtr($chunk),
            $got
        );
        $context->builder->store($context->builder->add($lenBefore, $got), $lenSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
        $lenFinal = $context->builder->load($lenSlot);
        $lenIsZero = $context->builder->icmp(Builder::INT_EQ, $lenFinal, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('read_bytes_empty');
        $makeBb = $fn->appendBasicBlock('read_bytes_make');
        $context->builder->branchIf($lenIsZero, $emptyBb, $makeBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('free'), $context->builder->load($bufSlot));
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $i64->constInt(0, false), $context->pointerFromStringConstant(''))
        );

        $context->builder->positionAtEnd($makeBb);
        $bufFinal = $context->builder->load($bufSlot);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $context->builder->sext($lenFinal, $i64), $bufFinal);
        $context->builder->call($context->lookupFunction('free'), $bufFinal);
        $context->builder->returnValue($result);
    }

    private static function emitStreamGetContents(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgc_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $maxlength = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zero64 = $i64->constInt(0, false);

        $badOffset = $context->builder->icmp(Builder::INT_SLT, $offset, $i64->constInt(-1, true));
        $failBb = $fn->appendBasicBlock('sgc_fail');
        $resolveBb = $fn->appendBasicBlock('sgc_resolve');
        $context->builder->branchIf($badOffset, $failBb, $resolveBb);

        $context->builder->positionAtEnd($resolveBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $seekBb = $fn->appendBasicBlock('sgc_seek');
        $context->builder->branchIf($fpNull, $failBb, $seekBb);

        $context->builder->positionAtEnd($seekBb);
        $hasOffset = $context->builder->icmp(Builder::INT_SGE, $offset, $zero64);
        $seekDoBb = $fn->appendBasicBlock('sgc_seek_do');
        $afterSeekBb = $fn->appendBasicBlock('sgc_after_seek');
        $context->builder->branchIf($hasOffset, $seekDoBb, $afterSeekBb);

        $context->builder->positionAtEnd($seekDoBb);
        $seekRc = $context->builder->call($context->lookupFunction('fseek'), $fp, $offset, $i32->constInt(0, false));
        $seekBad = $context->builder->icmp(Builder::INT_NE, $seekRc, $i32->constInt(0, false));
        $context->builder->branchIf($seekBad, $failBb, $afterSeekBb);

        $context->builder->positionAtEnd($afterSeekBb);
        $empty = $context->builder->icmp(Builder::INT_EQ, $maxlength, $zero64);
        $emptyBb = $fn->appendBasicBlock('sgc_empty');
        $readBb = $fn->appendBasicBlock('sgc_read');
        $context->builder->branchIf($empty, $emptyBb, $readBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zero64,
            $context->pointerFromStringConstant('')
        );
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $emptyStr
            )
        );

        $context->builder->positionAtEnd($readBb);
        $raw = $context->builder->call($context->lookupFunction('__phpc_read_stream_bytes'), $fp, $maxlength);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $raw
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitStreamCopyToStream(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('scts_entry');
        $context->builder->positionAtEnd($entry);

        $sourceHandle = $fn->getParam(0);
        $destHandle = $fn->getParam(1);
        $maxlength = $fn->getParam(2);
        $offset = $fn->getParam(3);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $bufCap = $sizeT->constInt(8192, false);

        $failBb = $fn->appendBasicBlock('scts_fail');
        $resolveBb = $fn->appendBasicBlock('scts_resolve');
        $srcFp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $sourceHandle);
        $srcNull = $context->builder->icmp(Builder::INT_EQ, $srcFp, $i8p->constNull());
        $context->builder->branchIf($srcNull, $failBb, $resolveBb);

        $context->builder->positionAtEnd($resolveBb);
        $dstFp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $destHandle);
        $dstNull = $context->builder->icmp(Builder::INT_EQ, $dstFp, $i8p->constNull());
        $seekBb = $fn->appendBasicBlock('scts_seek');
        $context->builder->branchIf($dstNull, $failBb, $seekBb);

        $context->builder->positionAtEnd($seekBb);
        $hasOffset = $context->builder->icmp(Builder::INT_SGT, $offset, $zero64);
        $seekDoBb = $fn->appendBasicBlock('scts_seek_do');
        $afterSeekBb = $fn->appendBasicBlock('scts_after_seek');
        $context->builder->branchIf($hasOffset, $seekDoBb, $afterSeekBb);

        $context->builder->positionAtEnd($seekDoBb);
        $seekRc = $context->builder->call($context->lookupFunction('fseek'), $srcFp, $offset, $zero32);
        $seekBad = $context->builder->icmp(Builder::INT_NE, $seekRc, $zero32);
        $context->builder->branchIf($seekBad, $failBb, $afterSeekBb);

        $context->builder->positionAtEnd($afterSeekBb);
        $empty = $context->builder->icmp(Builder::INT_EQ, $maxlength, $zero64);
        $emptyBb = $fn->appendBasicBlock('scts_empty');
        $loopInitBb = $fn->appendBasicBlock('scts_loop_init');
        $context->builder->branchIf($empty, $emptyBb, $loopInitBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($loopInitBb);
        $buf = $context->builder->alloca($i8, 8192, 'scts_buf');
        $loopBb = $fn->appendBasicBlock('scts_loop');
        $context->builder->branch($loopBb);
        $context->builder->positionAtEnd($loopBb);
        $totalPhi = $context->builder->phi($i64, 'scts_total');
        $totalPhi->addIncoming($zero64, $loopInitBb);

        $hasLimit = $context->builder->icmp(Builder::INT_SGT, $maxlength, $zero64);
        $remaining = $context->builder->sub($maxlength, $totalPhi);
        $doneLimit = $context->builder->icmp(Builder::INT_SLE, $remaining, $zero64);
        $doneBb = $fn->appendBasicBlock('scts_done');
        $readBb = $fn->appendBasicBlock('scts_read');
        $limitDone = $context->builder->and($hasLimit, $doneLimit);
        $context->builder->branchIf($limitDone, $doneBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $remainingSize = $context->builder->trunc($remaining, $sizeT);
        $smallRemaining = $context->builder->icmp(Builder::INT_SLT, $remainingSize, $bufCap);
        $limitedRead = $context->builder->select($smallRemaining, $remainingSize, $bufCap);
        $toRead = $context->builder->select($hasLimit, $limitedRead, $bufCap);
        $got = $context->builder->call($context->lookupFunction('fread'), $buf, $oneSize, $toRead, $srcFp);
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $zeroCheckBb = $fn->appendBasicBlock('scts_zero_check');
        $writeBb = $fn->appendBasicBlock('scts_write');
        $context->builder->branchIf($gotZero, $zeroCheckBb, $writeBb);

        $context->builder->positionAtEnd($zeroCheckBb);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $srcFp), $zero32);
        $context->builder->branchIf($hasErr, $failBb, $doneBb);

        $context->builder->positionAtEnd($writeBb);
        $wrote = $context->builder->call($context->lookupFunction('fwrite'), $buf, $oneSize, $got, $dstFp);
        $writeBad = $context->builder->icmp(Builder::INT_NE, $wrote, $got);
        $loopNextBb = $fn->appendBasicBlock('scts_next');
        $context->builder->branchIf($writeBad, $failBb, $loopNextBb);

        $context->builder->positionAtEnd($loopNextBb);
        $nextTotal = $context->builder->add($totalPhi, $context->builder->sext($wrote, $i64));
        $totalPhi->addIncoming($nextTotal, $loopNextBb);
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($totalPhi);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitStreamCopyToString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sctstr_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $maxlength = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $zero64 = $i64->constInt(0, false);

        $badOffset = $context->builder->icmp(Builder::INT_SLT, $offset, $zero64);
        $failBb = $fn->appendBasicBlock('sctstr_fail');
        $resolveBb = $fn->appendBasicBlock('sctstr_resolve');
        $context->builder->branchIf($badOffset, $failBb, $resolveBb);

        $context->builder->positionAtEnd($resolveBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $seekBb = $fn->appendBasicBlock('sctstr_seek');
        $context->builder->branchIf($fpNull, $failBb, $seekBb);

        $context->builder->positionAtEnd($seekBb);
        $seekRc = $context->builder->call($context->lookupFunction('fseek'), $fp, $offset, $i32->constInt(0, false));
        $seekBad = $context->builder->icmp(Builder::INT_NE, $seekRc, $i32->constInt(0, false));
        $afterSeekBb = $fn->appendBasicBlock('sctstr_after_seek');
        $context->builder->branchIf($seekBad, $failBb, $afterSeekBb);

        $context->builder->positionAtEnd($afterSeekBb);
        $empty = $context->builder->icmp(Builder::INT_EQ, $maxlength, $zero64);
        $emptyBb = $fn->appendBasicBlock('sctstr_empty');
        $readBb = $fn->appendBasicBlock('sctstr_read');
        $context->builder->branchIf($empty, $emptyBb, $readBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $zero64, $context->pointerFromStringConstant(''))
        );

        $context->builder->positionAtEnd($readBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__phpc_read_stream_bytes'), $fp, $maxlength)
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamReadJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
