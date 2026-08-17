<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin libz LLVM lowering for gz* / zlib_* JIT/AOT ABI (#6791, #26864).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ZlibJitHelper} to VmZlibCore segfaults under
 * thin AOT after c:main_before_php (#26864 — peer getcwd #26928 / getmypid #26944). Emit
 * compress2/uncompress + deflateInit2_/inflateInit2_ against linked libz; VM SSOT stays
 * {@see \PHPCompiler\ext\standard\VmZlibCore} (pure PHP).
 * php-src: ext/zlib/zlib.c — php_zlib_encode / php_zlib_decode
 */
final class StringZlibJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gzcompress',
        '__compiler_gzuncompress',
        '__compiler_gzdeflate',
        '__compiler_gzinflate',
        '__compiler_gzencode',
        '__compiler_gzdecode',
        '__compiler_zlib_encode',
        '__compiler_zlib_decode',
    ];

    private const DEFLATE_BYTES_HELPER = '__phpc_zc_deflate_bytes';
    private const INFLATE_BYTES_HELPER = '__phpc_zc_inflate_bytes';

    private const Z_STREAM_SIZE = 112;
    private const Z_NEXT_IN_OFFSET = 0;
    private const Z_AVAIL_IN_OFFSET = 8;
    private const Z_NEXT_OUT_OFFSET = 24;
    private const Z_AVAIL_OUT_OFFSET = 32;

    private const Z_OK = 0;
    private const Z_STREAM_END = 1;
    private const Z_DEFLATED = 8;
    private const Z_DEFAULT_STRATEGY = 0;
    private const Z_FINISH = 4;
    private const Z_DEFAULT_COMPRESSION = -1;

    private const PHP_ZLIB_ENCODING_RAW = 65534;
    private const PHP_ZLIB_ENCODING_DEFLATE = 65535;
    private const PHP_ZLIB_ENCODING_GZIP = 16;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gzcompress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibz($context);
        self::ensureRuntimeHelpers($context);
        self::emitDeflateBytes($context);
        self::emitInflateBytes($context);

        self::implementIfMissing($context, '__compiler_gzcompress', self::emitGzcompress(...));
        self::implementIfMissing($context, '__compiler_gzuncompress', self::emitGzuncompress(...));
        self::implementIfMissing($context, '__compiler_gzdeflate', self::emitGzdeflate(...));
        self::implementIfMissing($context, '__compiler_gzinflate', self::emitGzinflate(...));
        self::implementIfMissing($context, '__compiler_gzencode', self::emitGzencode(...));
        self::implementIfMissing($context, '__compiler_gzdecode', self::emitGzdecode(...));
        self::implementIfMissing($context, '__compiler_zlib_encode', self::emitZlibEncode(...));
        self::implementIfMissing($context, '__compiler_zlib_decode', self::emitZlibDecode(...));

        self::registerLinkedRuntime($context);
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
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = match ($name) {
            '__compiler_gzcompress',
            '__compiler_gzdeflate',
            '__compiler_gzencode',
            '__compiler_zlib_encode' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64, $i64)
            ),
            '__compiler_gzuncompress',
            '__compiler_gzinflate',
            '__compiler_gzdecode',
            '__compiler_zlib_decode' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64)
            ),
            default => throw new \LogicException('Unknown zlib JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibz(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['compress2', $i32, [$i8p, $i64p, $i8p, $i64, $i32]],
            ['uncompress', $i32, [$i8p, $i64p, $i8p, $i64]],
            ['compressBound', $i64, [$i64]],
            // libz exports deflateInit2_/inflateInit2_ (zlib.h macros), not the 6-arg names.
            ['deflateInit2_', $i32, [$i8p, $i32, $i32, $i32, $i32, $i32, $i8p, $i32]],
            ['deflate', $i32, [$i8p, $i32]],
            ['deflateEnd', $i32, [$i8p]],
            ['deflateBound', $i64, [$i8p, $i64]],
            ['inflateInit2_', $i32, [$i8p, $i32, $i8p, $i32]],
            ['inflate', $i32, [$i8p, $i32]],
            ['inflateEnd', $i32, [$i8p]],
            ['malloc', $i8p, [$i64]],
            ['free', $voidTy, [$i8p]],
            // memset(3) via LibcExtern::ensureMemsetDecl after always-on drop (#31863).
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
        LibcExtern::ensureMemsetDecl($context);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
    }

    private static function emitDeflateBytes(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::DEFLATE_BYTES_HELPER);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::DEFLATE_BYTES_HELPER, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::DEFLATE_BYTES_HELPER,
                $context->context->functionType($strPtr, false, $i8p, $i64, $i32, $i32)
            );
        $context->registerFunction(self::DEFLATE_BYTES_HELPER, $fn);

        $entry = $fn->appendBasicBlock('zcd_entry');
        $context->builder->positionAtEnd($entry);

        $in = $fn->getParam(0);
        $inLen = $fn->getParam(1);
        $level = $fn->getParam(2);
        $windowBits = $fn->getParam(3);
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();

        $zstrm = self::allocZeroedZStream($context);
        $zlibVersion = $context->pointerFromStringConstant('1.2.11');
        $initRc = $context->builder->call(
            $context->lookupFunction('deflateInit2_'),
            $zstrm,
            $level,
            $i32->constInt(self::Z_DEFLATED, false),
            $windowBits,
            $i32->constInt(8, false),
            $i32->constInt(self::Z_DEFAULT_STRATEGY, false),
            $zlibVersion,
            $i32->constInt(self::Z_STREAM_SIZE, false)
        );
        $initOk = $context->builder->icmp(Builder::INT_EQ, $initRc, $i32->constInt(self::Z_OK, false));
        $initFail = $fn->appendBasicBlock('zcd_init_fail');
        $doWork = $fn->appendBasicBlock('zcd_work');
        $context->builder->branchIf($initOk, $doWork, $initFail);

        $context->builder->positionAtEnd($initFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($doWork);
        $bound = $context->builder->call($context->lookupFunction('deflateBound'), $zstrm, $inLen);
        $boundSmall = $context->builder->icmp(Builder::INT_ULT, $bound, $i64->constInt(64, false));
        $outCap = $context->builder->select($boundSmall, $i64->constInt(64, false), $bound);
        $out = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $allocFail = $fn->appendBasicBlock('zcd_alloc_fail');
        $run = $fn->appendBasicBlock('zcd_run');
        $context->builder->branchIf($outNull, $allocFail, $run);

        $context->builder->positionAtEnd($allocFail);
        $context->builder->call($context->lookupFunction('deflateEnd'), $zstrm);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($run);
        $context->builder->store($in, self::zStreamFieldPtr($context, $zstrm, self::Z_NEXT_IN_OFFSET, $i8p->pointerType(0)));
        $context->builder->store(
            $context->builder->truncOrBitCast($inLen, $i32),
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_IN_OFFSET, $i32->pointerType(0))
        );
        $context->builder->store($out, self::zStreamFieldPtr($context, $zstrm, self::Z_NEXT_OUT_OFFSET, $i8p->pointerType(0)));
        $context->builder->store(
            $context->builder->truncOrBitCast($outCap, $i32),
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_OUT_OFFSET, $i32->pointerType(0))
        );

        $status = $context->builder->call(
            $context->lookupFunction('deflate'),
            $zstrm,
            $i32->constInt(self::Z_FINISH, false)
        );
        $availOut = $context->builder->load(
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_OUT_OFFSET, $i32->pointerType(0))
        );
        $outLen = $context->builder->sub($outCap, $context->builder->zExt($availOut, $i64));
        $context->builder->call($context->lookupFunction('deflateEnd'), $zstrm);

        $statusOk = $context->builder->icmp(
            Builder::INT_EQ,
            $status,
            $i32->constInt(self::Z_STREAM_END, false)
        );
        $statusFail = $fn->appendBasicBlock('zcd_status_fail');
        $done = $fn->appendBasicBlock('zcd_done');
        $context->builder->branchIf($statusOk, $done, $statusFail);

        $context->builder->positionAtEnd($statusFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $outLen));
        $context->builder->clearInsertionPosition();
    }

    private static function emitInflateBytes(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::INFLATE_BYTES_HELPER);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::INFLATE_BYTES_HELPER, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::INFLATE_BYTES_HELPER,
                $context->context->functionType($strPtr, false, $i8p, $i64, $i32, $i64)
            );
        $context->registerFunction(self::INFLATE_BYTES_HELPER, $fn);

        $entry = $fn->appendBasicBlock('zci_entry');
        $context->builder->positionAtEnd($entry);

        $in = $fn->getParam(0);
        $inLen = $fn->getParam(1);
        $windowBits = $fn->getParam(2);
        $maxLength = $fn->getParam(3);
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();

        $zstrm = self::allocZeroedZStream($context);
        $zlibVersion = $context->pointerFromStringConstant('1.2.11');
        $initRc = $context->builder->call(
            $context->lookupFunction('inflateInit2_'),
            $zstrm,
            $windowBits,
            $zlibVersion,
            $i32->constInt(self::Z_STREAM_SIZE, false)
        );
        $initOk = $context->builder->icmp(Builder::INT_EQ, $initRc, $i32->constInt(self::Z_OK, false));
        $initFail = $fn->appendBasicBlock('zci_init_fail');
        $calcCap = $fn->appendBasicBlock('zci_calc_cap');
        $context->builder->branchIf($initOk, $calcCap, $initFail);

        $context->builder->positionAtEnd($initFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($calcCap);
        $inSmall = $context->builder->icmp(Builder::INT_ULT, $inLen, $i64->constInt(64, false));
        $baseCap = $context->builder->select(
            $inSmall,
            $i64->constInt(64, false),
            $context->builder->mul($inLen, $i64->constInt(4, false))
        );
        $maxPos = $context->builder->icmp(Builder::INT_SGT, $maxLength, $i64->constInt(0, false));
        $maxSmaller = $context->builder->icmp(Builder::INT_ULT, $maxLength, $baseCap);
        $useMax = $context->builder->and($maxPos, $maxSmaller);
        $outCap = $context->builder->select($useMax, $maxLength, $baseCap);

        $out = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $allocFail = $fn->appendBasicBlock('zci_alloc_fail');
        $run = $fn->appendBasicBlock('zci_run');
        $context->builder->branchIf($outNull, $allocFail, $run);

        $context->builder->positionAtEnd($allocFail);
        $context->builder->call($context->lookupFunction('inflateEnd'), $zstrm);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($run);
        $context->builder->store($in, self::zStreamFieldPtr($context, $zstrm, self::Z_NEXT_IN_OFFSET, $i8p->pointerType(0)));
        $context->builder->store(
            $context->builder->truncOrBitCast($inLen, $i32),
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_IN_OFFSET, $i32->pointerType(0))
        );
        $context->builder->store($out, self::zStreamFieldPtr($context, $zstrm, self::Z_NEXT_OUT_OFFSET, $i8p->pointerType(0)));
        $context->builder->store(
            $context->builder->truncOrBitCast($outCap, $i32),
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_OUT_OFFSET, $i32->pointerType(0))
        );

        $status = $context->builder->call(
            $context->lookupFunction('inflate'),
            $zstrm,
            $i32->constInt(self::Z_FINISH, false)
        );
        $availOut = $context->builder->load(
            self::zStreamFieldPtr($context, $zstrm, self::Z_AVAIL_OUT_OFFSET, $i32->pointerType(0))
        );
        $outLen = $context->builder->sub($outCap, $context->builder->zExt($availOut, $i64));
        $context->builder->call($context->lookupFunction('inflateEnd'), $zstrm);

        $isStreamEnd = $context->builder->icmp(
            Builder::INT_EQ,
            $status,
            $i32->constInt(self::Z_STREAM_END, false)
        );
        $isOk = $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(self::Z_OK, false));
        $statusAccepted = $context->builder->or($isStreamEnd, $isOk);
        $statusFail = $fn->appendBasicBlock('zci_status_fail');
        $checkMax = $fn->appendBasicBlock('zci_check_max');
        $context->builder->branchIf($statusAccepted, $checkMax, $statusFail);

        $context->builder->positionAtEnd($statusFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($checkMax);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $outLen, $maxLength);
        $mustFail = $context->builder->and($maxPos, $tooLong);
        $maxFail = $fn->appendBasicBlock('zci_max_fail');
        $done = $fn->appendBasicBlock('zci_done');
        $context->builder->branchIf($mustFail, $maxFail, $done);

        $context->builder->positionAtEnd($maxFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $outLen));
        $context->builder->clearInsertionPosition();
    }

    private static function emitGzcompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $strPtr = $context->getTypeFromString('__string__*');

        $data = $fn->getParam(0);
        $level = $fn->getParam(1);
        $encoding = $fn->getParam(2);
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();
        $falseI1 = $i1->constInt(0, false);
        $trueI1 = $i1->constInt(1, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzcompress_fail');
        $body = $fn->appendBasicBlock('gzcompress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $lvl = self::normalizeLevel($context, $level);
        $isDeflate = self::isDeflateEncoding($context, $encoding);
        $isRaw = self::isRawEncoding($context, $encoding);
        $isGzip = self::isGzipEncoding($context, $encoding);
        $notRaw = $context->builder->select($isRaw, $falseI1, $trueI1);
        $notGzip = $context->builder->select($isGzip, $falseI1, $trueI1);
        $defaultEncoding = $context->builder->and($notRaw, $notGzip);
        $useCompress2 = $context->builder->or($isDeflate, $defaultEncoding);
        $pathCompress2 = $fn->appendBasicBlock('gzcompress_compress2');
        $pathDeflate = $fn->appendBasicBlock('gzcompress_deflate');
        $context->builder->branchIf($useCompress2, $pathCompress2, $pathDeflate);

        $context->builder->positionAtEnd($pathCompress2);
        $outLenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $bound = $context->builder->call($context->lookupFunction('compressBound'), $inLen);
        $context->builder->store($bound, $outLenSlot);
        $out = $context->builder->call($context->lookupFunction('malloc'), $bound);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $compressAllocFail = $fn->appendBasicBlock('gzcompress_alloc_fail');
        $compressRun = $fn->appendBasicBlock('gzcompress_run');
        $context->builder->branchIf($outNull, $compressAllocFail, $compressRun);

        $context->builder->positionAtEnd($compressAllocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($compressRun);
        $rc = $context->builder->call(
            $context->lookupFunction('compress2'),
            $out,
            $context->builder->pointerCast($outLenSlot, $i64p),
            $in,
            $inLen,
            $lvl
        );
        $rcOk = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::Z_OK, false));
        $compressFail = $fn->appendBasicBlock('gzcompress_rc_fail');
        $compressOk = $fn->appendBasicBlock('gzcompress_ok');
        $context->builder->branchIf($rcOk, $compressOk, $compressFail);

        $context->builder->positionAtEnd($compressFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($compressOk);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $context->builder->load($outLenSlot)));

        $context->builder->positionAtEnd($pathDeflate);
        $gzipPath = $fn->appendBasicBlock('gzcompress_gzip');
        $rawPath = $fn->appendBasicBlock('gzcompress_raw');
        $context->builder->branchIf($isGzip, $gzipPath, $rawPath);

        $context->builder->positionAtEnd($gzipPath);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::DEFLATE_BYTES_HELPER),
                $in,
                $inLen,
                $lvl,
                $i32->constInt(31, true)
            )
        );

        $context->builder->positionAtEnd($rawPath);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::DEFLATE_BYTES_HELPER),
                $in,
                $inLen,
                $lvl,
                $i32->constInt(-15, true)
            )
        );
    }

    private static function emitGzuncompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $strPtr = $context->getTypeFromString('__string__*');

        $data = $fn->getParam(0);
        $maxLength = $fn->getParam(1);
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzuncompress_fail');
        $body = $fn->appendBasicBlock('gzuncompress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $outLenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $baseLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $inLen, $i64->constInt(64, false)),
            $i64->constInt(64, false),
            $context->builder->mul($inLen, $i64->constInt(4, false))
        );
        $maxPos = $context->builder->icmp(Builder::INT_SGT, $maxLength, $i64->constInt(0, false));
        $maxSmaller = $context->builder->icmp(Builder::INT_ULT, $maxLength, $baseLen);
        $outLenInit = $context->builder->select(
            $context->builder->and($maxPos, $maxSmaller),
            $maxLength,
            $baseLen
        );
        $context->builder->store($outLenInit, $outLenSlot);
        $out = $context->builder->call($context->lookupFunction('malloc'), $outLenInit);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $allocFail = $fn->appendBasicBlock('gzuncompress_alloc_fail');
        $run = $fn->appendBasicBlock('gzuncompress_run');
        $context->builder->branchIf($outNull, $allocFail, $run);

        $context->builder->positionAtEnd($allocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($run);
        $rc = $context->builder->call(
            $context->lookupFunction('uncompress'),
            $out,
            $context->builder->pointerCast($outLenSlot, $i64p),
            $in,
            $inLen
        );
        $rcOk = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::Z_OK, false));
        $rcFail = $fn->appendBasicBlock('gzuncompress_rc_fail');
        $checkMax = $fn->appendBasicBlock('gzuncompress_check_max');
        $context->builder->branchIf($rcOk, $checkMax, $rcFail);

        $context->builder->positionAtEnd($rcFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($checkMax);
        $finalLen = $context->builder->load($outLenSlot);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $finalLen, $maxLength);
        $mustFail = $context->builder->and($maxPos, $tooLong);
        $maxFail = $fn->appendBasicBlock('gzuncompress_max_fail');
        $ok = $fn->appendBasicBlock('gzuncompress_ok');
        $context->builder->branchIf($mustFail, $maxFail, $ok);

        $context->builder->positionAtEnd($maxFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $finalLen));
    }

    private static function emitGzdeflate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $level = $fn->getParam(1);
        $encoding = $fn->getParam(2);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzdeflate_fail');
        $body = $fn->appendBasicBlock('gzdeflate_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $lvl = self::normalizeLevel($context, $level);
        $windowBits = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(-15, true), $windowBits);
        $isGzip = self::isGzipEncoding($context, $encoding);
        $isDeflate = self::isDeflateEncoding($context, $encoding);
        $isRaw = self::isRawEncoding($context, $encoding);
        $gzipBlock = $fn->appendBasicBlock('gzdeflate_gzip');
        $checkDeflate = $fn->appendBasicBlock('gzdeflate_check_deflate');
        $deflateSet = $fn->appendBasicBlock('gzdeflate_set_deflate');
        $checkRaw = $fn->appendBasicBlock('gzdeflate_check_raw');
        $rawSet = $fn->appendBasicBlock('gzdeflate_set_raw');
        $callBlock = $fn->appendBasicBlock('gzdeflate_call');
        $context->builder->branchIf($isGzip, $gzipBlock, $checkDeflate);

        $context->builder->positionAtEnd($gzipBlock);
        $context->builder->store($i32->constInt(31, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkDeflate);
        $context->builder->branchIf($isDeflate, $deflateSet, $checkRaw);

        $context->builder->positionAtEnd($deflateSet);
        $context->builder->store($i32->constInt(15, false), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkRaw);
        $context->builder->branchIf($isRaw, $rawSet, $callBlock);

        $context->builder->positionAtEnd($rawSet);
        $context->builder->store($i32->constInt(-15, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($callBlock);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::DEFLATE_BYTES_HELPER),
                $in,
                $inLen,
                $lvl,
                $context->builder->load($windowBits)
            )
        );
    }

    private static function emitGzinflate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $maxLength = $fn->getParam(1);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzinflate_fail');
        $body = $fn->appendBasicBlock('gzinflate_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::INFLATE_BYTES_HELPER),
                self::stringData($context, $data),
                self::stringLen($context, $data),
                $i32->constInt(-15, true),
                $maxLength
            )
        );
    }

    private static function emitGzencode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $level = $fn->getParam(1);
        $encoding = $fn->getParam(2);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzencode_fail');
        $body = $fn->appendBasicBlock('gzencode_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $lvl = self::normalizeLevel($context, $level);
        $windowBits = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(31, true), $windowBits);
        $isRaw = self::isRawEncoding($context, $encoding);
        $isDeflate = self::isDeflateEncoding($context, $encoding);
        $isGzip = self::isGzipEncoding($context, $encoding);
        $rawSet = $fn->appendBasicBlock('gzencode_set_raw');
        $checkDeflate = $fn->appendBasicBlock('gzencode_check_deflate');
        $deflateSet = $fn->appendBasicBlock('gzencode_set_deflate');
        $checkGzip = $fn->appendBasicBlock('gzencode_check_gzip');
        $gzipSet = $fn->appendBasicBlock('gzencode_set_gzip');
        $callBlock = $fn->appendBasicBlock('gzencode_call');
        $context->builder->branchIf($isRaw, $rawSet, $checkDeflate);

        $context->builder->positionAtEnd($rawSet);
        $context->builder->store($i32->constInt(-15, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkDeflate);
        $context->builder->branchIf($isDeflate, $deflateSet, $checkGzip);

        $context->builder->positionAtEnd($deflateSet);
        $context->builder->store($i32->constInt(15, false), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkGzip);
        $context->builder->branchIf($isGzip, $gzipSet, $callBlock);

        $context->builder->positionAtEnd($gzipSet);
        $context->builder->store($i32->constInt(31, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($callBlock);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::DEFLATE_BYTES_HELPER),
                $in,
                $inLen,
                $lvl,
                $context->builder->load($windowBits)
            )
        );
    }

    private static function emitGzdecode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $maxLength = $fn->getParam(1);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('gzdecode_fail');
        $body = $fn->appendBasicBlock('gzdecode_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::INFLATE_BYTES_HELPER),
                self::stringData($context, $data),
                self::stringLen($context, $data),
                $i32->constInt(31, true),
                $maxLength
            )
        );
    }

    private static function emitZlibEncode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $encoding = $fn->getParam(1);
        $level = $fn->getParam(2);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('zlib_encode_fail');
        $body = $fn->appendBasicBlock('zlib_encode_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $lvl = self::normalizeLevel($context, $level);
        $windowBits = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(-15, true), $windowBits);
        $isGzip = self::isGzipEncoding($context, $encoding);
        $isDeflate = self::isDeflateEncoding($context, $encoding);
        $isRaw = self::isRawEncoding($context, $encoding);
        $gzipBlock = $fn->appendBasicBlock('zlib_encode_gzip');
        $checkDeflate = $fn->appendBasicBlock('zlib_encode_check_deflate');
        $deflateSet = $fn->appendBasicBlock('zlib_encode_set_deflate');
        $checkRaw = $fn->appendBasicBlock('zlib_encode_check_raw');
        $rawSet = $fn->appendBasicBlock('zlib_encode_set_raw');
        $callBlock = $fn->appendBasicBlock('zlib_encode_call');
        $context->builder->branchIf($isGzip, $gzipBlock, $checkDeflate);

        $context->builder->positionAtEnd($gzipBlock);
        $context->builder->store($i32->constInt(31, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkDeflate);
        $context->builder->branchIf($isDeflate, $deflateSet, $checkRaw);

        $context->builder->positionAtEnd($deflateSet);
        $context->builder->store($i32->constInt(15, false), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($checkRaw);
        $context->builder->branchIf($isRaw, $rawSet, $callBlock);

        $context->builder->positionAtEnd($rawSet);
        $context->builder->store($i32->constInt(-15, true), $windowBits);
        $context->builder->branch($callBlock);

        $context->builder->positionAtEnd($callBlock);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction(self::DEFLATE_BYTES_HELPER),
                $in,
                $inLen,
                $lvl,
                $context->builder->load($windowBits)
            )
        );
    }

    private static function emitZlibDecode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = $fn->getParam(0);
        $maxLength = $fn->getParam(1);
        $nullString = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('zlib_decode_fail');
        $body = $fn->appendBasicBlock('zlib_decode_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $anyResult = $context->builder->call(
            $context->lookupFunction(self::INFLATE_BYTES_HELPER),
            $in,
            $inLen,
            $i32->constInt(47, false),
            $maxLength
        );
        $anyOk = $context->builder->icmp(Builder::INT_NE, $anyResult, $nullString);
        $retryBlock = $fn->appendBasicBlock('zlib_decode_retry');
        $doneOk = $fn->appendBasicBlock('zlib_decode_done_ok');
        $context->builder->branchIf($anyOk, $doneOk, $retryBlock);

        $context->builder->positionAtEnd($retryBlock);
        $rawResult = $context->builder->call(
            $context->lookupFunction(self::INFLATE_BYTES_HELPER),
            $in,
            $inLen,
            $i32->constInt(-15, true),
            $maxLength
        );
        $context->builder->returnValue($rawResult);

        $context->builder->positionAtEnd($doneOk);
        $context->builder->returnValue($anyResult);
    }

    private static function allocZeroedZStream(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $slot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::Z_STREAM_SIZE));
        $base = $context->builder->pointerCast($slot, $i8p);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $base,
            $i32->constInt(0, false),
            $i64->constInt(self::Z_STREAM_SIZE, false)
        );

        return $base;
    }

    private static function zStreamFieldPtr(Context $context, Value $zstrm, int $offset, $fieldPtrType): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->pointerCast(
            $context->builder->gep($zstrm, $i64->constInt($offset, false)),
            $fieldPtrType
        );
    }

    private static function stringLen(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($strObj, $map['length']));
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function stringFromBytes(Context $context, Value $data, Value $len): Value
    {
        return $context->builder->call($context->lookupFunction('__string__init'), $len, $data);
    }

    private static function normalizeLevel(Context $context, Value $level): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $asI32 = $context->builder->truncOrBitCast($level, $i32);
        $ltDefault = $context->builder->icmp(
            Builder::INT_SLT,
            $level,
            $i64->constInt(self::Z_DEFAULT_COMPRESSION, true)
        );
        $gtNine = $context->builder->icmp(Builder::INT_SGT, $level, $i64->constInt(9, false));
        $bounded = $context->builder->select($gtNine, $i32->constInt(9, false), $asI32);

        return $context->builder->select(
            $ltDefault,
            $i32->constInt(self::Z_DEFAULT_COMPRESSION, true),
            $bounded
        );
    }

    private static function isGzipEncoding(Context $context, Value $encoding): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $enc16 = $context->builder->icmp(
            Builder::INT_EQ,
            $encoding,
            $i64->constInt(self::PHP_ZLIB_ENCODING_GZIP, false)
        );
        $enc31 = $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(31, true));

        return $context->builder->or($enc16, $enc31);
    }

    private static function isRawEncoding(Context $context, Value $encoding): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $encRaw = $context->builder->icmp(
            Builder::INT_EQ,
            $encoding,
            $i64->constInt(self::PHP_ZLIB_ENCODING_RAW, false)
        );
        $encNeg15 = $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(-15, true));

        return $context->builder->or($encRaw, $encNeg15);
    }

    private static function isDeflateEncoding(Context $context, Value $encoding): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $encDeflate = $context->builder->icmp(
            Builder::INT_EQ,
            $encoding,
            $i64->constInt(self::PHP_ZLIB_ENCODING_DEFLATE, false)
        );
        $encNeg16 = $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(-16, true));
        $enc15 = $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(15, false));

        return $context->builder->or($encDeflate, $context->builder->or($encNeg16, $enc15));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringZlibJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
