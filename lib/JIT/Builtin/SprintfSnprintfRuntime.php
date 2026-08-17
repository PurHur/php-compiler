<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * argv==1 sprintf/printf via libc snprintf — bypasses broken NestedJIT pack path (#31963).
 *
 * php-src: ext/standard/formatted_print.c — single-arg format dispatch
 */
final class SprintfSnprintfRuntime
{
    private const BUF_SIZE = 256;

    /** Format with one __value__ arg using the user format string as snprintf pattern. */
    public static function formatOneArg(
        Context $context,
        LlvmFunction $fn,
        Value $fmtSep,
        Value $argv
    ): Value {
        self::ensureDecls($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $entry = $context->builder->gep($argv, $i64->constInt(0, false));
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $fmtNul = self::nullTerminatedCopy($context, $fmtSep);
        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $outChar = $context->builder->pointerCast($outBuf, $charPtr);
        $bufSize = $sizeT->constInt(self::BUF_SIZE, false);

        $doubleBb = $fn->appendBasicBlock('sprintf_snprintf_double');
        $longBb = $fn->appendBasicBlock('sprintf_snprintf_long');
        $stringBb = $fn->appendBasicBlock('sprintf_snprintf_string');
        $fallbackBb = $fn->appendBasicBlock('sprintf_snprintf_fallback');
        $doneBb = $fn->appendBasicBlock('sprintf_snprintf_done');

        $isDouble = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_FLOAT, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false)
            )
        );
        $afterDouble = $fn->appendBasicBlock('sprintf_snprintf_after_double');
        $context->builder->branchIf($isDouble, $doubleBb, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = $fn->appendBasicBlock('sprintf_snprintf_after_long');
        $context->builder->branchIf($isLong, $longBb, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $fallbackBb);

        $context->builder->positionAtEnd($doubleBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $writtenD = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dbl
        );
        $endD = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $lng = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $writtenL = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $lng
        );
        $endL = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $strNul = self::nullTerminatedCopy($context, $strSep);
        $writtenS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $strNul
        );
        $endS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        $writtenF = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul
        );
        $endF = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $i32 = $context->getTypeFromString('int32');
        $writtenPhi = $context->builder->phi($i32);
        $writtenPhi->addIncoming($writtenD, $endD);
        $writtenPhi->addIncoming($writtenL, $endL);
        $writtenPhi->addIncoming($writtenS, $endS);
        $writtenPhi->addIncoming($writtenF, $endF);

        $len = $context->builder->zExt($writtenPhi, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($outBuf, $i8p)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $outBuf);
        $context->builder->call($context->lookupFunction('__mm__free'), $fmtNul);

        return $result;
    }

    private static function nullTerminatedCopy(Context $context, Value $strSep): Value
    {
        $stringMap = $context->structFieldMap['__string__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');

        $len = $context->builder->load($context->builder->structGep($strSep, $stringMap['length']));
        $data = $context->builder->structGep($strSep, $stringMap['value']);
        $allocSize = $context->builder->add($len, $sizeT->constInt(1, false));
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $allocSize);
        $bufI8 = $context->builder->pointerCast($buf, $i8p);
        LibcExtern::ensureMemcpyImplemented($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $bufI8,
            $data,
            $len
        );
        $nulPtr = $context->builder->gep($bufI8, $len);
        $context->builder->store($i8->constInt(0, false), $nulPtr);

        return $context->builder->pointerCast($buf, $charPtr);
    }

    private static function ensureDecls(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                'snprintf' => [$i32, true, [$charPtr, $sizeT, $charPtr]],
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
                '__string__separate' => [$strPtr, false, [$strPtr]],
                '__value__readDouble' => [$double, false, [$valuePtr]],
                '__value__readLong' => [$i64, false, [$valuePtr]],
                '__value__readString' => [$strPtr, false, [$valuePtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $ft = $context->context->functionType($ret, $vararg, ...$params);
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
        LibcExtern::ensureMemcpyImplemented($context);
    }
}
