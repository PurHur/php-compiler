<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin LLVM bridge to libc crypt(3) for password/crypt SSOT (#9275, #14182).
 *
 * Avoids routing VmPasswordPure through the user crypt() builtin (AOT recursion).
 * php-src: ext/standard/crypt.c — PHP_FN(crypt)
 */
final class LibcryptThinRuntime
{
    private const ABI = '__compiler_libcrypt';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('libcrypt_entry');
        $fail = $fn->appendBasicBlock('libcrypt_fail');
        $ok = $fn->appendBasicBlock('libcrypt_ok');
        $context->builder->positionAtEnd($entry);

        $keyCstr = self::stringDataPtr($context, $fn->getParam(0));
        $saltCstr = self::stringDataPtr($context, $fn->getParam(1));
        $resultCstr = $context->builder->call(
            self::libcryptDecl($context),
            $keyCstr,
            $saltCstr
        );

        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $resultCstr,
            $i8p->constNull()
        );
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $resultCstr);
        $lenForCmp = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $lenForCmp,
            $i64->constInt(0, false)
        );
        $emptyBb = $fn->appendBasicBlock('libcrypt_empty');
        $wrapBb = $fn->appendBasicBlock('libcrypt_wrap');
        $context->builder->branchIf($isEmpty, $emptyBb, $wrapBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($wrapBb);
        $firstChar = $context->builder->load($resultCstr);
        $isStar = $context->builder->icmp(
            Builder::INT_EQ,
            $firstChar,
            $context->getTypeFromString('int8')->constInt(ord('*'), false)
        );
        $starBb = $fn->appendBasicBlock('libcrypt_star');
        $doneBb = $fn->appendBasicBlock('libcrypt_done');
        $context->builder->branchIf($isStar, $starBb, $doneBb);

        $context->builder->positionAtEnd($starBb);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($doneBb);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $out = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $resultCstr
        );
        $context->builder->returnValue($out);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction(self::ABI, $fn);
        self::restoreInsertBlock($context, $savedBlock);
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

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            try {
                $context->builder->positionAtEnd($savedBlock);
            } catch (\Throwable) {
                $context->builder->clearInsertionPosition();
            }

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
