<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for zstd_compress/zstd_decompress (php-src ext/zstd/zstd.c; #6387, #8564).
 */
final class StringZstdJit
{
    private const MIN_LEVEL = 1;
    private const MAX_LEVEL = 22;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_zstd_compress',
        '__compiler_zstd_decompress',
    ];

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_zstd_compress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::ensureLibzstd($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__compiler_zstd_compress', self::emitZstdCompress(...));
        self::implementIfMissing($context, '__compiler_zstd_decompress', self::emitZstdDecompress(...));

        self::registerLinkedRuntime($context);
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
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = match ($name) {
            '__compiler_zstd_compress' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64)
            ),
            '__compiler_zstd_decompress' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            default => throw new \LogicException('Unknown zstd JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibzstd(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['ZSTD_compress', $i64, [$i8p, $i64, $i8p, $i64, $i32]],
            ['ZSTD_compressBound', $i64, [$i64]],
            ['ZSTD_decompress', $i64, [$i8p, $i64, $i8p, $i64]],
            ['ZSTD_getFrameContentSize', $i64, [$i8p, $i64]],
            ['ZSTD_isError', $i32, [$i64]],
            ['malloc', $i8p, [$i64]],
            ['free', $voidTy, [$i8p]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
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
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitZstdCompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $level = $fn->getParam(1);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $fail = $fn->appendBasicBlock('zstd_compress_fail');
        $body = $fn->appendBasicBlock('zstd_compress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $levelI32 = $context->builder->truncOrBitCast($level, $i32);
        $levelOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $levelI32, $i32->constInt(self::MIN_LEVEL, false)),
            $context->builder->icmp(Builder::INT_SLE, $levelI32, $i32->constInt(self::MAX_LEVEL, false))
        );
        $failLevel = $fn->appendBasicBlock('zstd_compress_fail_level');
        $work = $fn->appendBasicBlock('zstd_compress_work');
        $context->builder->branchIf($levelOk, $work, $failLevel);

        $context->builder->positionAtEnd($failLevel);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($work);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $bound = $context->builder->call($context->lookupFunction('ZSTD_compressBound'), $inLen);
        $boundOk = $context->builder->icmp(Builder::INT_SGT, $bound, $i64->constInt(0, false));
        $failBound = $fn->appendBasicBlock('zstd_compress_fail_bound');
        $alloc = $fn->appendBasicBlock('zstd_compress_alloc');
        $context->builder->branchIf($boundOk, $alloc, $failBound);

        $context->builder->positionAtEnd($failBound);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($alloc);
        $dest = $context->builder->call($context->lookupFunction('malloc'), $bound);
        $written = $context->builder->call(
            $context->lookupFunction('ZSTD_compress'),
            $dest,
            $bound,
            $in,
            $inLen,
            $levelI32
        );
        $isErr = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ZSTD_isError'), $written),
            $i32->constInt(0, false)
        );
        $failRc = $fn->appendBasicBlock('zstd_compress_fail_rc');
        $okRc = $fn->appendBasicBlock('zstd_compress_ok_rc');
        $context->builder->branchIf($isErr, $failRc, $okRc);

        $context->builder->positionAtEnd($failRc);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($okRc);
        $result = self::stringFromBytes($context, $dest, $written);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($result);
    }

    private static function emitZstdDecompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $fail = $fn->appendBasicBlock('zstd_decompress_fail');
        $body = $fn->appendBasicBlock('zstd_decompress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $zeroI64 = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $inLen, $zeroI64);
        $emptyBb = $fn->appendBasicBlock('zstd_decompress_empty');
        $workBb = $fn->appendBasicBlock('zstd_decompress_work');
        $context->builder->branchIf($isZero, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::stringFromBytes($context, $in, $zeroI64));

        $context->builder->positionAtEnd($workBb);
        $contentSize = $context->builder->call(
            $context->lookupFunction('ZSTD_getFrameContentSize'),
            $in,
            $inLen
        );
        $hasKnown = $context->builder->icmp(Builder::INT_SGT, $contentSize, $zeroI64);
        $estimate = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $context->builder->mul($inLen, $i64->constInt(4, false)), $i64->constInt(64, false)),
            $i64->constInt(64, false),
            $context->builder->mul($inLen, $i64->constInt(4, false))
        );
        $dstCap = $context->builder->select($hasKnown, $contentSize, $estimate);
        $capOk = $context->builder->icmp(Builder::INT_SGT, $dstCap, $zeroI64);
        $failCap = $fn->appendBasicBlock('zstd_decompress_fail_cap');
        $alloc = $fn->appendBasicBlock('zstd_decompress_alloc');
        $context->builder->branchIf($capOk, $alloc, $failCap);

        $context->builder->positionAtEnd($failCap);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($alloc);
        $dest = $context->builder->call($context->lookupFunction('malloc'), $dstCap);
        $written = $context->builder->call(
            $context->lookupFunction('ZSTD_decompress'),
            $dest,
            $dstCap,
            $in,
            $inLen
        );
        $isErr = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ZSTD_isError'), $written),
            $i32->constInt(0, false)
        );
        $failRc = $fn->appendBasicBlock('zstd_decompress_fail_rc');
        $okRc = $fn->appendBasicBlock('zstd_decompress_ok_rc');
        $context->builder->branchIf($isErr, $failRc, $okRc);

        $context->builder->positionAtEnd($failRc);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($okRc);
        $result = self::stringFromBytes($context, $dest, $written);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($result);
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringZstdJit implement');
            }
            $context->registerFunction($name, $fn);
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
