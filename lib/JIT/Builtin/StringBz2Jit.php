<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for bzcompress/bzdecompress (php-src ext/bz2/bz2.c; issue #3402).
 */
final class StringBz2Jit
{
    private const BZ_OK = 0;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_bzcompress',
        '__compiler_bzdecompress',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_bzcompress');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibbz2($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__compiler_bzcompress', self::emitBzcompress(...));
        self::implementIfMissing($context, '__compiler_bzdecompress', self::emitBzdecompress(...));

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

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = match ($name) {
            '__compiler_bzcompress' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64, $i64)
            ),
            '__compiler_bzdecompress' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64)
            ),
            default => throw new \LogicException('Unknown bz2 JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibbz2(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['BZ2_bzBuffToBuffCompress', $i32, [$i8p, $i32p, $i8p, $i32, $i32, $i32, $i32]],
            ['BZ2_bzBuffToBuffDecompress', $i32, [$i8p, $i32p, $i8p, $i32, $i32, $i32]],
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

    private static function emitBzcompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $context->getTypeFromString('int32*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $blockSize = $fn->getParam(1);
        $workFactor = $fn->getParam(2);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $fail = $fn->appendBasicBlock('bz2_compress_fail');
        $body = $fn->appendBasicBlock('bz2_compress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $inLenI32 = $context->builder->truncOrBitCast($inLen, $i32);

        $destLen = $context->builder->add(
            $context->builder->add($inLen, $context->builder->udiv($inLen, $i64->constInt(100, false))),
            $i64->constInt(600, false)
        );
        $dest = $context->builder->call($context->lookupFunction('malloc'), $destLen);
        $destLenSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($context->builder->truncOrBitCast($destLen, $i32), $destLenSlot);

        $blockSizeI32 = $context->builder->truncOrBitCast($blockSize, $i32);
        $workFactorI32 = $context->builder->truncOrBitCast($workFactor, $i32);
        $rc = $context->builder->call(
            $context->lookupFunction('BZ2_bzBuffToBuffCompress'),
            $dest,
            $destLenSlot,
            $in,
            $inLenI32,
            $blockSizeI32,
            $i32->constInt(0, false),
            $workFactorI32
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::BZ_OK, false));
        $failRc = $fn->appendBasicBlock('bz2_compress_fail_rc');
        $okRc = $fn->appendBasicBlock('bz2_compress_ok_rc');
        $context->builder->branchIf($ok, $okRc, $failRc);

        $context->builder->positionAtEnd($failRc);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($okRc);
        $writtenI32 = $context->builder->load($destLenSlot);
        $written = $context->builder->zextOrBitCast($writtenI32, $i64);
        $result = self::stringFromBytes($context, $dest, $written);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($result);
    }

    private static function emitBzdecompress(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullString = $strPtr->constNull();

        $src = $fn->getParam(0);
        $small = $fn->getParam(1);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullString);
        $fail = $fn->appendBasicBlock('bz2_decompress_fail');
        $body = $fn->appendBasicBlock('bz2_decompress_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($body);
        $in = self::stringData($context, $src);
        $inLen = self::stringLen($context, $src);
        $inLenI32 = $context->builder->truncOrBitCast($inLen, $i32);

        $destLen = $context->builder->mul($inLen, $i64->constInt(32, false));
        $minLen = $context->builder->icmp(Builder::INT_ULT, $destLen, $i64->constInt(4096, false));
        $destLen = $context->builder->select($minLen, $i64->constInt(4096, false), $destLen);

        $dest = $context->builder->call($context->lookupFunction('malloc'), $destLen);
        $destLenSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($context->builder->truncOrBitCast($destLen, $i32), $destLenSlot);

        $smallI32 = $context->builder->truncOrBitCast($small, $i32);
        $rc = $context->builder->call(
            $context->lookupFunction('BZ2_bzBuffToBuffDecompress'),
            $dest,
            $destLenSlot,
            $in,
            $inLenI32,
            $smallI32,
            $i32->constInt(0, false)
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::BZ_OK, false));
        $failRc = $fn->appendBasicBlock('bz2_decompress_fail_rc');
        $okRc = $fn->appendBasicBlock('bz2_decompress_ok_rc');
        $context->builder->branchIf($ok, $okRc, $failRc);

        $context->builder->positionAtEnd($failRc);
        $context->builder->call($context->lookupFunction('free'), $dest);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($okRc);
        $writtenI32 = $context->builder->load($destLenSlot);
        $written = $context->builder->zextOrBitCast($writtenI32, $i64);
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
                throw new \LogicException($name.' missing after StringBz2Jit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
