<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for phpc_libcrypt_kernel() — thin libc crypt(3) (#9275, #26773).
 *
 * Nested leaf inside {@see LibcryptJitHelper} only. Avoids NestedJIT lowering of
 * PHP {@see \crypt()} into {@see PasswordJitHelper} (AOT recursion / null hash).
 * php-src: ext/standard/crypt.c — PHP_FN(crypt)
 */
final class JitLibcryptKernel
{
    /** @return Value `__string__*` — null pointer when crypt fails */
    public static function invoke(Context $context, Value $keyStr, Value $saltStr): Value
    {
        LibcExtern::register($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $nullOut = $strPtr->constNull();
        $tag = 'libcrypt_k_'.bin2hex(\random_bytes(3));

        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $emptyBlock = BasicBlockHelper::append($context, $tag.'_empty');
        $wrapBlock = BasicBlockHelper::append($context, $tag.'_wrap');
        $starBlock = BasicBlockHelper::append($context, $tag.'_star');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $mergeBlock = BasicBlockHelper::append($context, $tag.'_merge');

        $keyCstr = self::stringDataPtr($context, $keyStr);
        $saltCstr = self::stringDataPtr($context, $saltStr);
        $resultCstr = $context->builder->call(
            self::libcryptDecl($context),
            $keyCstr,
            $saltCstr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resultCstr, $i8p->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $len = $context->builder->call($context->lookupFunction('strlen'), $resultCstr);
        $lenForCmp = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $lenForCmp,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isEmpty, $emptyBlock, $wrapBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($wrapBlock);
        $firstChar = $context->builder->load($resultCstr);
        $isStar = $context->builder->icmp(
            Builder::INT_EQ,
            $firstChar,
            $i8->constInt(\ord('*'), false)
        );
        $context->builder->branchIf($isStar, $starBlock, $doneBlock);

        $context->builder->positionAtEnd($starBlock);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($doneBlock);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $out = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $resultCstr
        );
        $doneEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($out, $doneEnd);
        $phi->addIncoming($nullOut, $failEnd);

        return $phi;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function libcryptDecl(Context $context): LlvmFunction
    {
        try {
            return $context->lookupFunction('crypt');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('crypt', $ft);
            $context->registerFunction('crypt', $fn);

            return $fn;
        }
    }
}
