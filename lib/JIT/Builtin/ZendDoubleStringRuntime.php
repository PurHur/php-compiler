<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT float→string honoring PG(precision) (#21963).
 *
 * LLVM snprintf over {@see IniRuntime::loadPrecision()}.
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmZendDoubleString}.
 * php-src: Zend/zend_operators.c — _convert_to_string float branch
 */
final class ZendDoubleStringRuntime
{
    private const ABI = '__compiler_zend_double_string';

    private const ENTRY = 'zend_double_string_entry';

    private static int $seq = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function format(Context $context, Value $doubleVal): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::snprintfCall($context, $doubleVal, '%.14g', null);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $doubleVal
        );
    }

    /** serialize() wire `d:…;` without NestedJIT float cast (#31963). */
    public static function formatSerializeWire(Context $context, Value $doubleVal): Value
    {
        self::ensureSerializeWireLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_zend_serialize_double'),
            $doubleVal
        );
    }

    public static function ensureSerializeWireLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_zend_serialize_double');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_zend_serialize_double', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureDecls($context);

        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                '__compiler_zend_serialize_double',
                $context->context->functionType($strPtr, false, $double)
            );
        $entry = $fn->appendBasicBlock('zend_ser_double_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            self::snprintfCall($context, $fn->getParam(0), 'd:%.16g;', null)
        );
        $context->registerFunction('__compiler_zend_serialize_double', $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        IniRuntime::ensureLinked($context);
        self::ensureDecls($context);

        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $double)
            );

        $entry = $fn->appendBasicBlock(self::ENTRY);
        $context->builder->positionAtEnd($entry);
        $result = self::emitBody($context, $fn, $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitBody(Context $context, LlvmFunction $fn, Value $val): Value
    {
        $s = ++self::$seq;
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        // Tokenize NAN/INF via snprintf then map; keeps AOT free of isnan/isinf link quirks.
        $raw = self::snprintfCall($context, $val, '%.16g', null);

        $nanBb = $fn->appendBasicBlock('zds_nan_'.$s);
        $infBb = $fn->appendBasicBlock('zds_inf_'.$s);
        $ninfBb = $fn->appendBasicBlock('zds_ninf_'.$s);
        $okBb = $fn->appendBasicBlock('zds_ok_'.$s);
        $doneBb = $fn->appendBasicBlock('zds_done_'.$s);

        $nanLit = $context->builder->load($context->constantStringFromString('nan'));
        // Use JitStringCompare SSOT — bare __string__compare was never implemented (#21948 AOT link).
        $isNan = JitStringCompare::identical($context, $raw, $nanLit);
        $afterNan = $fn->appendBasicBlock('zds_after_nan_'.$s);
        $context->builder->branchIf($isNan, $nanBb, $afterNan);

        $context->builder->positionAtEnd($nanBb);
        $nanStr = $context->builder->load($context->constantStringFromString('NAN'));
        $nanEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterNan);
        $infLit = $context->builder->load($context->constantStringFromString('inf'));
        $isInf = JitStringCompare::identical($context, $raw, $infLit);
        $afterInf = $fn->appendBasicBlock('zds_after_inf_'.$s);
        $context->builder->branchIf($isInf, $infBb, $afterInf);

        $context->builder->positionAtEnd($infBb);
        $infStr = $context->builder->load($context->constantStringFromString('INF'));
        $infEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterInf);
        $ninfLit = $context->builder->load($context->constantStringFromString('-inf'));
        $isNinf = JitStringCompare::identical($context, $raw, $ninfLit);
        $context->builder->branchIf($isNinf, $ninfBb, $okBb);

        $context->builder->positionAtEnd($ninfBb);
        $ninfStr = $context->builder->load($context->constantStringFromString('-INF'));
        $ninfEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $prec = IniRuntime::loadPrecision($context);
        $useDefault = $context->builder->icmp(
            Builder::INT_EQ,
            $prec,
            $i32->constInt(0, false)
        );
        $prec = $context->builder->select($useDefault, $i32->constInt(14, true), $prec);
        $isNegPrec = $context->builder->icmp(Builder::INT_SLT, $prec, $i32->constInt(0, false));
        $dtoaBb = $fn->appendBasicBlock('zds_dtoa_'.$s);
        $precBb = $fn->appendBasicBlock('zds_prec_'.$s);
        $joinBb = $fn->appendBasicBlock('zds_join_'.$s);
        $context->builder->branchIf($isNegPrec, $dtoaBb, $precBb);

        $context->builder->positionAtEnd($dtoaBb);
        // raw already %.16g — reuse for precision=-1
        $context->builder->branch($joinBb);
        $dtoaEnd = $dtoaBb;

        $context->builder->positionAtEnd($precBb);
        $precStr = self::snprintfCall($context, $val, '%.*g', $prec);
        $precEnd = $context->builder->getInsertBlock();
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $okPhi = $context->builder->phi($strPtr);
        $okPhi->addIncoming($raw, $dtoaEnd);
        $okPhi->addIncoming($precStr, $precEnd);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($nanStr, $nanEnd);
        $phi->addIncoming($infStr, $infEnd);
        $phi->addIncoming($ninfStr, $ninfEnd);
        $phi->addIncoming($okPhi, $okEnd);

        return $phi;
    }

    private static function snprintfCall(
        Context $context,
        Value $doubleVal,
        string $fmt,
        ?Value $precisionArg
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $charPtr);
        if (null === $precisionArg) {
            // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
            LibcExtern::ensureSnprintf($context);
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $doubleVal
            );
        } else {
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $precisionArg,
                $doubleVal
            );
        }
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function ensureDecls(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                'snprintf' => [$i32, true, [$charPtr, $sizeT, $charPtr]],
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $charPtr]],
                // Unused memcmp decl dropped with LibcExtern always-on (#31954).
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $ft = $context->context->functionType($ret, $vararg, ...$params);
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
    }
}
