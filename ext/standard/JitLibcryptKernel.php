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
 * LLVM lowering for NestedJIT `@crypt` — thin libc crypt(3) (#9275, #26773, #29545).
 *
 * Nested leaf via {@see crypt::call} when {@see NestedJitCompileScope} is active.
 * Avoids re-entering {@see PasswordJitHelper} through `__compiler_crypt` (AOT recursion / null hash).
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

    /**
     * crypt(3) + strcmp against expected hash — NestedJIT-safe bcrypt verify (#26773).
     *
     * @return Value i64 — 1 on match, 0 otherwise
     */
    public static function verifyMatch(Context $context, Value $passwordStr, Value $hashStr): Value
    {
        LibcExtern::register($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $tag = 'libcrypt_v_'.bin2hex(\random_bytes(3));

        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $cmpBlock = BasicBlockHelper::append($context, $tag.'_cmp');
        $mergeBlock = BasicBlockHelper::append($context, $tag.'_merge');

        $keyCstr = self::stringDataPtr($context, $passwordStr);
        $hashCstr = self::stringDataPtr($context, $hashStr);
        $resultCstr = $context->builder->call(
            self::libcryptDecl($context),
            $keyCstr,
            $hashCstr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resultCstr, $i8p->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $firstChar = $context->builder->load($resultCstr);
        $isStar = $context->builder->icmp(
            Builder::INT_EQ,
            $firstChar,
            $i8->constInt(\ord('*'), false)
        );
        $context->builder->branchIf($isStar, $failBlock, $cmpBlock);

        $context->builder->positionAtEnd($cmpBlock);
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $resultCstr,
            $hashCstr
        );
        $cmpI32 = $cmp->typeOf() === $i32 ? $cmp : $context->builder->trunc($cmp, $i32);
        $eq = $context->builder->icmp(Builder::INT_EQ, $cmpI32, $i32->constInt(0, false));
        $matched = $context->builder->select($eq, $one, $zero);
        $cmpEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($matched, $cmpEnd);
        $phi->addIncoming($zero, $failEnd);

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
        // Compiler-prefixed lookup — never registerFunction('crypt'): NestedJIT @crypt
        // must not interpose libxcrypt crypt(3) (#26861 / #29545).
        try {
            return $context->lookupFunction('__compiler_libc_crypt');
        } catch (\Throwable) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
        // IR/linker symbol stays "crypt" so -lcrypt resolves crypt(3); lookup alias only.
        $probe = $context->module->getNamedFunction('crypt');
        $fn = null !== $probe ? $probe : $context->module->addFunction('crypt', $ft);
        $context->registerFunction('__compiler_libc_crypt', $fn);

        return $fn;
    }
}
