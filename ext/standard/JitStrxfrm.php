<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for strxfrm() — thin libc strxfrm(3) (#4376, #30420).
 *
 * Used while NestedJIT compiles {@see StrxfrmJitHelper} `\strxfrm` via
 * {@see \PHPCompiler\JIT\Builtin\StringStrxfrm} (nl_langinfo #30404 / fnmatch #30383 shape).
 * php-src: ext/standard/string.c — PHP_FUNCTION(strxfrm)
 */
final class JitStrxfrm
{
    /** @return Value `__string__*` */
    public static function invokeLibcLeaf(Context $context, JITVariable $string): Value
    {
        self::ensureLibcStrxfrm($context);

        $srcStr = JitStringBuiltinArg::lower($context, $string, 'strxfrm', 0, 'string');
        $srcData = self::stringDataPtr($context, $srcStr);

        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $nullSrc = $i8p->constNull();
        $zero = $sizeT->constInt(0, false);

        $needLen = $context->builder->call(
            $context->lookupFunction('strxfrm'),
            $nullSrc,
            $srcData,
            $zero
        );
        $needLenI64 = $context->builder->zExt($needLen, $i64);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $needLenI64, $i64->constInt(0, false));

        $emptyBlock = BasicBlockHelper::append($context, 'strxfrm_empty');
        $workBlock = BasicBlockHelper::append($context, 'strxfrm_work');
        $doneBlock = BasicBlockHelper::append($context, 'strxfrm_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = self::compileTimeString($context, '');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $one = $i64->constInt(1, false);
        $bufSize = $context->builder->truncOrBitCast(
            $context->builder->add($needLenI64, $one),
            $sizeT
        );
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $context->builder->call(
            $context->lookupFunction('strxfrm'),
            $bufChar,
            $srcData,
            $bufSize
        );
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $needLenI64,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $workEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($emptyStr, $emptyBlock);
        $phi->addIncoming($resultStr, $workEnd);

        return $phi;
    }

    private static function compileTimeString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function ensureLibcStrxfrm(Context $context): void
    {
        try {
            $context->lookupFunction('strxfrm');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $fn = $context->module->addFunction(
                'strxfrm',
                $context->context->functionType($sizeT, false, $i8p, $i8p, $sizeT)
            );
            $context->registerFunction('strxfrm', $fn);
        }
    }
}
