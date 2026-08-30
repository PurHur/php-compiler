<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for incremental zlib — thin AOT buffer + one-shot {@see JitZlib} (#35885 leftover of #4656).
 *
 * php-src: ext/zlib/zlib.c PHP_FUNCTION(deflate_init) / deflate_add / inflate_*.
 * Matches {@see VmZlibContext} when host streaming contexts are unavailable (buffer until non-NO_FLUSH).
 */
final class JitZlibIncremental
{
    private static int $serial = 0;

    public static function dispatch(Context $context, string $name, JITVariable ...$args): Value
    {
        return match ($name) {
            'deflate_init' => self::init($context, ZlibIncrementalJitSupport::DEFLATE_CLASS, 'deflate_init', ...$args),
            'inflate_init' => self::init($context, ZlibIncrementalJitSupport::INFLATE_CLASS, 'inflate_init', ...$args),
            'deflate_add' => self::add($context, ZlibIncrementalJitSupport::DEFLATE_CLASS, 'deflate_add', true, ...$args),
            'inflate_add' => self::add($context, ZlibIncrementalJitSupport::INFLATE_CLASS, 'inflate_add', false, ...$args),
            'inflate_get_status' => self::getLongProp(
                $context,
                ZlibIncrementalJitSupport::INFLATE_CLASS,
                'inflate_get_status',
                ZlibIncrementalJitSupport::PROP_STATUS,
                ...$args
            ),
            'inflate_get_read_len' => self::getLongProp(
                $context,
                ZlibIncrementalJitSupport::INFLATE_CLASS,
                'inflate_get_read_len',
                ZlibIncrementalJitSupport::PROP_READ_LEN,
                ...$args
            ),
            default => throw new \LogicException($name.'() JIT dispatch missing (#35885)'),
        };
    }

    private static function init(
        Context $context,
        string $className,
        string $function,
        JITVariable ...$args
    ): Value {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('%s() expects at least 1 argument, %d given', $function, $argc)
                    : \sprintf('%s() expects at most 2 arguments, %d given', $function, $argc)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $encoding = JitStrictIntArg::lower($context, $args[0], $function, 1, 'encoding');
        if (null !== $args[0]->compileTimeLong) {
            VmZlibContext::assertValidEncoding((int) $args[0]->compileTimeLong, $function, 1, 'encoding');
        }
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_ENC, $encoding);
        self::storeLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_LEVEL, $level);
        self::storeString($context, $obj, $className, ZlibIncrementalJitSupport::PROP_BUF, self::emptyString($context));
        self::storeLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_STATUS, $i64->constInt(0, false));
        self::storeLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_READ_LEN, $i64->constInt(0, false));

        return self::boxObject($context, $obj);
    }

    private static function add(
        Context $context,
        string $className,
        string $function,
        bool $deflate,
        JITVariable ...$args
    ): Value {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('%s() expects at least 2 arguments, %d given', $function, $argc)
                    : \sprintf('%s() expects at most 3 arguments, %d given', $function, $argc)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $obj = self::readObject($context, $args[0]);
        $chunk = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], $function, 1, 'data')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], $function, 1, 'data');
        $i64 = $context->getTypeFromString('int64');
        $flush = $i64->constInt(\defined('ZLIB_NO_FLUSH') ? \ZLIB_NO_FLUSH : 0, false);
        if (isset($args[2])) {
            $flush = JitStrictIntArg::lower($context, $args[2], $function, 3, 'flush_mode');
        }
        $buf = self::loadString($context, $obj, $className, ZlibIncrementalJitSupport::PROP_BUF);
        $joined = self::appendStringPtr($context, $buf, $chunk);
        self::storeString($context, $obj, $className, ZlibIncrementalJitSupport::PROP_BUF, $joined);
        $chunkLen = $context->builder->load(
            $context->builder->structGep($chunk, $context->structFieldMap['__string__']['length'])
        );
        $prevRead = self::loadLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_READ_LEN);
        self::storeLong(
            $context,
            $obj,
            $className,
            ZlibIncrementalJitSupport::PROP_READ_LEN,
            $context->builder->add($prevRead, $chunkLen)
        );

        $id = (string) (++self::$serial);
        $noFlushBb = BasicBlockHelper::append($context, 'zinc_nf_'.$id);
        $flushBb = BasicBlockHelper::append($context, 'zinc_fl_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'zinc_done_'.$id);
        $noFlush = $context->builder->icmp(
            Builder::INT_EQ,
            $flush,
            $i64->constInt(\defined('ZLIB_NO_FLUSH') ? \ZLIB_NO_FLUSH : 0, false)
        );
        $context->builder->branchIf($noFlush, $noFlushBb, $flushBb);

        $context->builder->positionAtEnd($noFlushBb);
        $emptyResult = self::boxedString($context, self::emptyString($context));
        $nfTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($flushBb);
        $enc = self::loadLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_ENC);
        $level = self::loadLong($context, $obj, $className, ZlibIncrementalJitSupport::PROP_LEVEL);
        $payload = self::loadString($context, $obj, $className, ZlibIncrementalJitSupport::PROP_BUF);
        $transformed = $deflate
            ? self::compressBuffered($context, $payload, $level, $enc)
            : self::decompressBuffered($context, $payload, $enc);
        self::storeString($context, $obj, $className, ZlibIncrementalJitSupport::PROP_BUF, self::emptyString($context));
        $isFinish = $context->builder->icmp(
            Builder::INT_EQ,
            $flush,
            $i64->constInt(\defined('ZLIB_FINISH') ? \ZLIB_FINISH : 4, false)
        );
        $finishBb = BasicBlockHelper::append($context, 'zinc_fin_'.$id);
        $afterFinBb = BasicBlockHelper::append($context, 'zinc_afin_'.$id);
        $context->builder->branchIf($isFinish, $finishBb, $afterFinBb);
        $context->builder->positionAtEnd($finishBb);
        self::storeLong(
            $context,
            $obj,
            $className,
            ZlibIncrementalJitSupport::PROP_STATUS,
            $i64->constInt(1, false)
        );
        $context->builder->branch($afterFinBb);
        $context->builder->positionAtEnd($afterFinBb);
        $flTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($emptyResult->typeOf());
        $phi->addIncoming($emptyResult, $nfTail);
        $phi->addIncoming($transformed, $flTail);

        return $phi;
    }

    private static function getLongProp(
        Context $context,
        string $className,
        string $function,
        string $prop,
        JITVariable ...$args
    ): Value {
        $argc = \count($args);
        if (1 !== $argc) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('%s() expects exactly 1 argument, %d given', $function, $argc)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $obj = self::readObject($context, $args[0]);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            self::loadLong($context, $obj, $className, $prop)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function compressBuffered(
        Context $context,
        Value $data,
        Value $level,
        Value $encoding
    ): Value {
        return self::encodingSwitch(
            $context,
            $encoding,
            static fn (Context $ctx) => JitZlib::encode(
                $ctx,
                $data,
                $level,
                $ctx->getTypeFromString('int64')->constInt(\ZLIB_ENCODING_GZIP, false)
            ),
            static fn (Context $ctx) => JitZlib::compress(
                $ctx,
                $data,
                $level,
                $ctx->getTypeFromString('int64')->constInt(\ZLIB_ENCODING_DEFLATE, false)
            ),
            static fn (Context $ctx) => JitZlib::deflate(
                $ctx,
                $data,
                $level,
                $ctx->getTypeFromString('int64')->constInt(\ZLIB_ENCODING_RAW, true)
            )
        );
    }

    private static function decompressBuffered(Context $context, Value $data, Value $encoding): Value
    {
        $neg1 = $context->getTypeFromString('int64')->constInt(-1, true);

        return self::encodingSwitch(
            $context,
            $encoding,
            static fn (Context $ctx) => JitZlib::decode($ctx, $data, $neg1),
            static fn (Context $ctx) => JitZlib::uncompress($ctx, $data, $neg1),
            static fn (Context $ctx) => JitZlib::inflate($ctx, $data, $neg1)
        );
    }

    /**
     * @param callable(Context): Value $gzip
     * @param callable(Context): Value $deflate
     * @param callable(Context): Value $raw
     */
    private static function encodingSwitch(
        Context $context,
        Value $encoding,
        callable $gzip,
        callable $deflate,
        callable $raw
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$serial);
        $gzipBb = BasicBlockHelper::append($context, 'zenc_gz_'.$id);
        $notGzBb = BasicBlockHelper::append($context, 'zenc_ngz_'.$id);
        $deflateBb = BasicBlockHelper::append($context, 'zenc_df_'.$id);
        $rawBb = BasicBlockHelper::append($context, 'zenc_raw_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'zenc_done_'.$id);
        $isGzip = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(\ZLIB_ENCODING_GZIP, false)),
            $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(16, false))
        );
        $context->builder->branchIf($isGzip, $gzipBb, $notGzBb);

        $context->builder->positionAtEnd($gzipBb);
        $gzVal = $gzip($context);
        $gzTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($notGzBb);
        $isDeflate = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(\ZLIB_ENCODING_DEFLATE, false)),
            $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(65535, false))
        );
        $context->builder->branchIf($isDeflate, $deflateBb, $rawBb);

        $context->builder->positionAtEnd($deflateBb);
        $dfVal = $deflate($context);
        $dfTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($rawBb);
        $rawVal = $raw($context);
        $rawTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($gzVal->typeOf());
        $phi->addIncoming($gzVal, $gzTail);
        $phi->addIncoming($dfVal, $dfTail);
        $phi->addIncoming($rawVal, $rawTail);

        return $phi;
    }

    private static function boxedString(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    private static function emptyString(Context $context): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    private static function appendStringPtr(Context $context, Value $left, Value $right): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load($context->builder->structGep($left, $map['length']));
        $rightLen = $context->builder->load($context->builder->structGep($right, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $leftEmpty = $context->builder->icmp(Builder::INT_SLE, $leftLen, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $emptyBb = BasicBlockHelper::append($context, 'zcat_e_'.$id);
        $copyBb = BasicBlockHelper::append($context, 'zcat_c_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'zcat_d_'.$id);
        $context->builder->branchIf($leftEmpty, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyVal = $right;
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        $totalLen = $context->builder->add($leftLen, $rightLen);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $totalLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store($totalLen, $context->builder->structGep($dest, $map['length']));
        $leftPtr = $context->builder->structGep($left, $map['value']);
        $rightPtr = $context->builder->structGep($right, $map['value']);
        $context->intrinsic->memcpy($destPtr, $leftPtr, $leftLen, false);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $leftLen),
            $rightPtr,
            $rightLen,
            false
        );
        $copyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($dest->typeOf());
        $phi->addIncoming($emptyVal, $emptyTail);
        $phi->addIncoming($dest, $copyTail);

        return $phi;
    }

    private static function storeString(Context $context, Value $obj, string $className, string $prop, Value $strPtr): void
    {
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strPtr);
        $context->type->object->storeInstanceProperty($obj, $className, $prop, $strVar);
    }

    private static function loadString(Context $context, Value $obj, string $className, string $prop): Value
    {
        $strVar = $context->type->object->propertyFetch($obj, $className, $prop);

        return $context->helper->loadValue($strVar);
    }

    private static function storeLong(Context $context, Value $obj, string $className, string $prop, Value $longVal): void
    {
        $handleVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $longVal);
        $context->type->object->storeInstanceProperty($obj, $className, $prop, $handleVar);
    }

    private static function loadLong(Context $context, Value $obj, string $className, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch($obj, $className, $prop);

        return $context->helper->loadValue($handleVar);
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }
}
