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
 * LLVM lowering for zstd_* builtins (issue #6387, #8564).
 *
 * Mirrors ext/zstd/VmZstdNative.php — no host \\zstd_compress() delegation.
 */
final class StringZstdJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_zstd_compress',
        '__compiler_zstd_decompress',
    ];

    private const DEFAULT_LEVEL = 3;

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
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
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
    }

    private static function ensureLibzstd(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

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
        $entry = $fn->appendBasicBlock('zstd_compress_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $levelIn = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('zstd_compress_fail');
        $body = $fn->appendBasicBlock('zstd_compress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $level = self::normalizeLevel($context, $levelIn);
        $levelOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $level, $i32->constInt(1, false)),
            $context->builder->icmp(Builder::INT_SLE, $level, $i32->constInt(22, false))
        );
        $levelFail = $fn->appendBasicBlock('zstd_compress_level_fail');
        $work = $fn->appendBasicBlock('zstd_compress_work');
        $context->builder->branchIf($levelOk, $work, $levelFail);

        $context->builder->positionAtEnd($levelFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($work);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $bound = $context->builder->call($context->lookupFunction('ZSTD_compressBound'), $inLen);
        $boundOk = $context->builder->icmp(Builder::INT_SGT, $bound, $i64->constInt(0, false));
        $boundFail = $fn->appendBasicBlock('zstd_compress_bound_fail');
        $alloc = $fn->appendBasicBlock('zstd_compress_alloc');
        $context->builder->branchIf($boundOk, $alloc, $boundFail);

        $context->builder->positionAtEnd($boundFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($alloc);
        $out = $context->builder->call($context->lookupFunction('malloc'), $bound);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $allocFail = $fn->appendBasicBlock('zstd_compress_alloc_fail');
        $run = $fn->appendBasicBlock('zstd_compress_run');
        $context->builder->branchIf($outNull, $allocFail, $run);

        $context->builder->positionAtEnd($allocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($run);
        $written = $context->builder->call(
            $context->lookupFunction('ZSTD_compress'),
            $out,
            $bound,
            $in,
            $inLen,
            $level
        );
        $isError = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ZSTD_isError'), $written),
            $i32->constInt(0, false)
        );
        $compressFail = $fn->appendBasicBlock('zstd_compress_error');
        $compressOk = $fn->appendBasicBlock('zstd_compress_ok');
        $context->builder->branchIf($isError, $compressFail, $compressOk);

        $context->builder->positionAtEnd($compressFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($compressOk);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $written));
    }

    private static function emitZstdDecompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('zstd_decompress_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullString = $strPtr->constNull();
        $nullBytes = $i8p->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $nullString);
        $fail = $fn->appendBasicBlock('zstd_decompress_fail');
        $body = $fn->appendBasicBlock('zstd_decompress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $data);
        $inLen = self::stringLen($context, $data);
        $empty = $context->builder->icmp(Builder::INT_EQ, $inLen, $i64->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('zstd_decompress_empty');
        $work = $fn->appendBasicBlock('zstd_decompress_work');
        $context->builder->branchIf($empty, $emptyBb, $work);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                self::literalCstr($context, '')
            )
        );

        $context->builder->positionAtEnd($work);
        $contentSize = $context->builder->call(
            $context->lookupFunction('ZSTD_getFrameContentSize'),
            $in,
            $inLen
        );
        $hasContentSize = $context->builder->icmp(Builder::INT_SGT, $contentSize, $i64->constInt(0, false));
        $fallbackCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $inLen, $i64->constInt(64, false)),
            $i64->constInt(64, false),
            $context->builder->mul($inLen, $i64->constInt(4, false))
        );
        $dstCap = $context->builder->select($hasContentSize, $contentSize, $fallbackCap);
        $capOk = $context->builder->icmp(Builder::INT_SGT, $dstCap, $i64->constInt(0, false));
        $capFail = $fn->appendBasicBlock('zstd_decompress_cap_fail');
        $alloc = $fn->appendBasicBlock('zstd_decompress_alloc');
        $context->builder->branchIf($capOk, $alloc, $capFail);

        $context->builder->positionAtEnd($capFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($alloc);
        $out = $context->builder->call($context->lookupFunction('malloc'), $dstCap);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullBytes);
        $allocFail = $fn->appendBasicBlock('zstd_decompress_alloc_fail');
        $run = $fn->appendBasicBlock('zstd_decompress_run');
        $context->builder->branchIf($outNull, $allocFail, $run);

        $context->builder->positionAtEnd($allocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($run);
        $written = $context->builder->call(
            $context->lookupFunction('ZSTD_decompress'),
            $out,
            $dstCap,
            $in,
            $inLen
        );
        $isError = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ZSTD_isError'), $written),
            $i32->constInt(0, false)
        );
        $decompressFail = $fn->appendBasicBlock('zstd_decompress_error');
        $decompressOk = $fn->appendBasicBlock('zstd_decompress_ok');
        $context->builder->branchIf($isError, $decompressFail, $decompressOk);

        $context->builder->positionAtEnd($decompressFail);
        $context->builder->call($context->lookupFunction('free'), $out);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($decompressOk);
        $context->builder->returnValue(self::stringFromBytes($context, $out, $written));
    }

    private static function normalizeLevel(Context $context, Value $level): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $asI32 = $context->builder->truncOrBitCast($level, $i32);
        $ltDefault = $context->builder->icmp(
            Builder::INT_SLT,
            $level,
            $i64->constInt(self::DEFAULT_LEVEL, false)
        );

        return $context->builder->select(
            $ltDefault,
            $i32->constInt(self::DEFAULT_LEVEL, false),
            $asI32
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

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
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
