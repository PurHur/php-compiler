<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM __phpc_format_serialize_double — compiler INI serialize_precision (#7103).
 */
final class StringSerializeDoubleJit
{
    private const BUF_SIZE = 64;

    private const MAX_SIG_DIGITS = 17;

    public static function implement(Context $context): void
    {
        IniRuntime::ensureLinked($context);

        $probe = $context->module->getNamedFunction('__phpc_format_serialize_double');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_format_serialize_double', $probe);

            return;
        }

        $restore = $context->builder->getInsertBlock();
        self::ensureLibc($context);

        $doubleTy = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $charPtr = $context->getTypeFromString('char*');
        $fn = $context->module->addFunction(
            '__phpc_format_serialize_double',
            $context->context->functionType($strPtr, false, $doubleTy, $i32)
        );
        $context->registerFunction('__phpc_format_serialize_double', $fn);

        $entry = $fn->appendBasicBlock('fmt_entry');
        $context->builder->positionAtEnd($entry);

        $val = $fn->getParam(0);
        $precision = $fn->getParam(1);
        $zeroI32 = $i32->constInt(0, false);
        $negOne = $i32->constInt(-1, true);
        $useDefault = $context->builder->icmp(Builder::INT_SLT, $precision, $zeroI32);

        $bbDefault = $fn->appendBasicBlock('fmt_default');
        $bbFixed = $fn->appendBasicBlock('fmt_fixed');
        $bbMerge = $fn->appendBasicBlock('fmt_merge');
        $resultSlot = $context->builder->alloca($strPtr, 1, 'fmt_out');

        $context->builder->branchIf($useDefault, $bbDefault, $bbFixed);

        $context->builder->positionAtEnd($bbFixed);
        $fixed = self::snprintfDouble($context, $val, $precision, '%.*G');
        $context->builder->store($fixed, $resultSlot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbDefault);
        $digitsSlot = $context->builder->alloca($i32, 1, 'fmt_digits');
        $context->builder->store($i32->constInt(1, false), $digitsSlot);
        $loopHead = $fn->appendBasicBlock('fmt_loop_head');
        $loopBody = $fn->appendBasicBlock('fmt_loop_body');
        $loopDone = $fn->appendBasicBlock('fmt_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $digits = $context->builder->load($digitsSlot);
        $done = $context->builder->icmp(
            Builder::INT_SGT,
            $digits,
            $i32->constInt(self::MAX_SIG_DIGITS, false)
        );
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $candidate = self::snprintfDouble($context, $val, $digits, '%.*G');
        $parsed = self::parseDoubleString($context, $candidate);
        $matches = $context->builder->fcmp(Builder::REAL_OEQ, $parsed, $val);
        $useBb = $fn->appendBasicBlock('fmt_use');
        $nextBb = $fn->appendBasicBlock('fmt_next');
        $context->builder->branchIf($matches, $useBb, $nextBb);

        $context->builder->positionAtEnd($useBb);
        $context->builder->store($candidate, $resultSlot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store(
            $context->builder->addNoSignedWrap($digits, $i32->constInt(1, false)),
            $digitsSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $fallback = self::snprintfDouble(
            $context,
            $val,
            $i32->constInt(self::MAX_SIG_DIGITS, false),
            '%.*G'
        );
        $context->builder->store($fallback, $resultSlot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->builder->clearInsertionPosition();
        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $charPtrPtr = $charPtr->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $doubleTy = $context->getTypeFromString('double');

        foreach (
            [
                ['snprintf', $i32, [$charPtr, $sizeT, $charPtr, $i32, $doubleTy]],
                ['strtod', $doubleTy, [$charPtr, $charPtrPtr]],
            ] as [$name, $ret, $params]
        ) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction(
                    $name,
                    $context->context->functionType($context->getTypeFromString($ret), false, ...$params)
                );
            }
        }
    }

    private static function snprintfDouble(Context $context, Value $val, Value $precision, string $fmt): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(self::BUF_SIZE, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmtCstr = $context->builder->pointerCast($context->constantFromString($fmt), $charPtr);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmtCstr,
            $precision,
            $val
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function parseDoubleString(Context $context, Value $strPtr): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $charPtrPtr = $charPtr->pointerType(0);
        $strMap = $context->structFieldMap['__string__'];
        $data = $context->builder->load($context->builder->structGep($strPtr, $strMap['value']));

        return $context->builder->call(
            $context->lookupFunction('strtod'),
            $context->builder->pointerCast($data, $charPtr),
            $charPtrPtr->constNull()
        );
    }
}
