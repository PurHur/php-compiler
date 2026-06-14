<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for lzf_compress/lzf_decompress (php-src ext/lzf/lzf.c; issue #6384).
 */
final class StringLzfJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_lzf_compress',
        '__compiler_lzf_decompress',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_lzf_compress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLiblzf($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__compiler_lzf_compress', self::emitCompress(...));
        self::implementIfMissing($context, '__compiler_lzf_decompress', self::emitDecompress(...));

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
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLiblzf(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['lzf_compress', $i32, [$i8p, $i32, $i8p, $i32]],
            ['lzf_decompress', $i32, [$i8p, $i32, $i8p, $i32]],
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

    private static function emitCompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $emptyBb = $fn->appendBasicBlock('lzf_compress_empty');
        $bodyBb = $fn->appendBasicBlock('lzf_compress_body');
        $context->builder->branchIf($isNull, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($bodyBb);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $inLenI32 = $context->builder->truncOrBitCast($inLen, $i32);
        $zeroI64 = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $inLen, $zeroI64);
        $zeroBb = $fn->appendBasicBlock('lzf_compress_zero');
        $workBb = $fn->appendBasicBlock('lzf_compress_work');
        $context->builder->branchIf($isZero, $zeroBb, $workBb);

        $context->builder->positionAtEnd($zeroBb);
        $empty = self::stringFromBytes($context, $in, $zeroI64);
        $context->builder->returnValue($empty);

        $context->builder->positionAtEnd($workBb);
        $sixtyFour = $i64->constInt(64, false);
        $sixteen = $i64->constInt(16, false);
        $outCap = $context->builder->add(
            $context->builder->add($inLen, $context->builder->udiv($inLen, $sixtyFour)),
            $sixteen
        );
        $dest = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outCapI32 = $context->builder->truncOrBitCast($outCap, $i32);
        $written = $context->builder->call(
            $context->lookupFunction('lzf_compress'),
            $in,
            $inLenI32,
            $dest,
            $outCapI32
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $written, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('lzf_compress_fail');
        $okBb = $fn->appendBasicBlock('lzf_compress_ok');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($okBb);
        $writtenI64 = $context->builder->zextOrBitCast($written, $i64);
        $result = self::stringFromBytes($context, $dest, $writtenI64);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($result);
    }

    private static function emitDecompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $emptyBb = $fn->appendBasicBlock('lzf_decompress_empty');
        $bodyBb = $fn->appendBasicBlock('lzf_decompress_body');
        $context->builder->branchIf($isNull, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($bodyBb);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $inLenI32 = $context->builder->truncOrBitCast($inLen, $i32);
        $zeroI64 = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $inLen, $zeroI64);
        $zeroBb = $fn->appendBasicBlock('lzf_decompress_zero');
        $loopHead = $fn->appendBasicBlock('lzf_decompress_loop_head');
        $context->builder->branchIf($isZero, $zeroBb, $loopHead);

        $context->builder->positionAtEnd($zeroBb);
        $empty = self::stringFromBytes($context, $in, $zeroI64);
        $context->builder->returnValue($empty);

        $eight = $i64->constInt(8, false);
        $sixtyFour = $i64->constInt(64, false);
        $one = $i64->constInt(1, false);
        $maxAttempts = $i64->constInt(8, false);
        $initialCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $inLen, $sixtyFour),
            $context->builder->mulNoSignedWrap($inLen, $eight),
            $sixtyFour
        );

        $capSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $attemptSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($initialCap, $capSlot);
        $context->builder->store($zeroI64, $attemptSlot);
        $context->builder->branch($loopHead);

        $loopBody = $fn->appendBasicBlock('lzf_decompress_loop_body');
        $loopDone = $fn->appendBasicBlock('lzf_decompress_loop_done');
        $loopOk = $fn->appendBasicBlock('lzf_decompress_loop_ok');
        $loopFail = $fn->appendBasicBlock('lzf_decompress_loop_fail');

        $context->builder->positionAtEnd($loopHead);
        $attempt = $context->builder->load($attemptSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $attempt, $maxAttempts);
        $context->builder->branchIf($atEnd, $loopFail, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $outCap = $context->builder->load($capSlot);
        $dest = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outCapI32 = $context->builder->truncOrBitCast($outCap, $i32);
        $written = $context->builder->call(
            $context->lookupFunction('lzf_decompress'),
            $in,
            $inLenI32,
            $dest,
            $outCapI32
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $written, $i32->constInt(0, false));
        $context->builder->branchIf($ok, $loopOk, $loopDone);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->store($context->builder->addNoSignedWrap($attempt, $one), $attemptSlot);
        $context->builder->store($context->builder->mulNoSignedWrap($outCap, $i64->constInt(2, false)), $capSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopOk);
        $writtenI64 = $context->builder->zextOrBitCast($written, $i64);
        $result = self::stringFromBytes($context, $dest, $writtenI64);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($loopFail);
        $context->builder->returnValue($nullString);
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
                throw new \LogicException($name.' missing after StringLzfJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
