<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for variadic min()/max() (#4347, php-src ext/standard/array.c php_min_max).
 */
final class JitMinMax
{
    private static int $compareSeq = 0;

    public static function invoke(Context $context, bool $pickMin, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \LogicException('min()/max() requires at least one argument in this compiler build');
        }
        if (self::allPlainIntScalars($args)) {
            return self::reduceNativeLongValues($context, $pickMin, self::lowerPlainIntScalars($context, $args));
        }
        if (self::allNativeLong($args)) {
            return self::reduceNativeLong($context, $pickMin, $args);
        }
        if (self::allNativeNumeric($args)) {
            return self::reduceNativeDouble($context, $pickMin, $args);
        }

        return self::reduceNumericBoxes($context, $pickMin, $args);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function allPlainIntScalars(array $args): bool
    {
        foreach ($args as $arg) {
            if (!self::isPlainIntScalar($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True only for compile-time int-shaped operands.
     *
     * Do not treat bare {@see JitValueBox::isValueOperand} boxes as ints: script locals
     * and array-dim fetches are TYPE_VALUE and may hold strings. Routing those through
     * {@see JitLongArg::lower} coerces non-numeric strings to 0 (#23951). Known int
     * immediates keep the long fast path via {@see JITVariable::$compileTimeLong}.
     */
    private static function isPlainIntScalar(JITVariable $arg): bool
    {
        if (null !== $arg->compileTimeLong) {
            return true;
        }

        return JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NULL === $arg->type;
    }

    /**
     * @param list<JITVariable> $args
     */
    public static function canLowerPlainIntPair(array $args): bool
    {
        return 2 === \count($args)
            && self::isPlainIntScalar($args[0])
            && self::isPlainIntScalar($args[1]);
    }

    /**
     * @param list<JITVariable> $args
     *
     * @return list<Value>
     */
    private static function lowerPlainIntScalars(Context $context, array $args): array
    {
        $out = [];
        foreach ($args as $i => $arg) {
            $out[] = JitLongArg::lower($context, $arg, 'min()/max() argument #'.($i + 1));
        }

        return $out;
    }

    /**
     * @param list<Value> $values
     */
    private static function reduceNativeLongValues(Context $context, bool $pickMin, array $values): Value
    {
        $best = $values[0];
        foreach (\array_slice($values, 1) as $candidate) {
            $cmp = $context->builder->icmp(
                $pickMin ? Builder::INT_SLT : Builder::INT_SGT,
                $candidate,
                $best
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }

        return $best;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function allNativeLong(array $args): bool
    {
        foreach ($args as $arg) {
            if (JITVariable::TYPE_NATIVE_LONG !== $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function allNativeNumeric(array $args): bool
    {
        foreach ($args as $arg) {
            if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
                && JITVariable::TYPE_NATIVE_DOUBLE !== $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function reduceNativeLong(Context $context, bool $pickMin, array $args): Value
    {
        $best = $context->helper->loadValue($args[0]);
        foreach (\array_slice($args, 1) as $arg) {
            $candidate = $context->helper->loadValue($arg);
            $cmp = $context->builder->icmp(
                $pickMin ? Builder::INT_SLT : Builder::INT_SGT,
                $candidate,
                $best
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }

        return $best;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function reduceNativeDouble(Context $context, bool $pickMin, array $args): Value
    {
        $double = $context->getTypeFromString('double');
        $best = pow::toJitDouble($context, $args[0], $double);
        foreach (\array_slice($args, 1) as $arg) {
            $candidate = pow::toJitDouble($context, $arg, $double);
            $cmp = $context->builder->fcmp(
                Builder::REAL_OGT,
                $pickMin ? $best : $candidate,
                $pickMin ? $candidate : $best
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }

        return $best;
    }

    /**
     * Boxed / mixed scalars — return winning __value__* (#23779).
     *
     * @param list<JITVariable> $args
     */
    private static function reduceNumericBoxes(Context $context, bool $pickMin, array $args): Value
    {
        $bestPtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        foreach (\array_slice($args, 1) as $arg) {
            $candPtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $pickCand = self::shouldPickCandidate($context, $pickMin, $bestPtr, $candPtr);
            $bestPtr = $context->builder->select($pickCand, $candPtr, $bestPtr);
        }

        return $bestPtr;
    }

    private static function shouldPickCandidate(
        Context $context,
        bool $pickMin,
        Value $bestPtr,
        Value $candPtr
    ): Value {
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $bestType = $context->builder->load($context->builder->structGep($bestPtr, $map['type']));
        $candType = $context->builder->load($context->builder->structGep($candPtr, $map['type']));
        $stringTy = $i8->constInt(JITVariable::TYPE_STRING, false);
        $bothString = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $bestType, $stringTy),
            $context->builder->icmp(Builder::INT_EQ, $candType, $stringTy)
        );

        $tag = 'n'.(++self::$compareSeq);
        $stringBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_str');
        $numericBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_num');
        $done = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($bothString, $stringBlock, $numericBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strCmp = self::stringPtrSpaceship($context, $bestPtr, $candPtr);
        $zero = $context->getTypeFromString('int32')->constInt(0, false);
        $stringPick = $context->builder->icmp(
            $pickMin ? Builder::INT_SGT : Builder::INT_SLT,
            $strCmp,
            $zero
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($numericBlock);
        $bestD = self::toCompareDouble($context, $bestPtr);
        $candD = self::toCompareDouble($context, $candPtr);
        $numericPick = $context->builder->fcmp(
            Builder::REAL_OGT,
            $pickMin ? $bestD : $candD,
            $pickMin ? $candD : $bestD
        );
        $numericEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'jit_min_max_'.$tag.'_pick');
        $phi->addIncoming($stringPick, $stringEnd);
        $phi->addIncoming($numericPick, $numericEnd);

        return $phi;
    }

    private static function stringPtrSpaceship(Context $context, Value $leftPtr, Value $rightPtr): Value
    {
        $leftStr = $context->builder->call($context->lookupFunction('__value__readString'), $leftPtr);
        $rightStr = $context->builder->call($context->lookupFunction('__value__readString'), $rightPtr);
        $strMap = $context->structFieldMap['__string__'];
        $leftData = $context->builder->structGep($leftStr, $strMap['value']);
        $rightData = $context->builder->structGep($rightStr, $strMap['value']);

        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);

        return $context->builder->call(
            $context->lookupFunction('strcmp'),
            $leftData,
            $rightData
        );
    }

    private static function toCompareDouble(Context $context, Value $valuePtr): Value
    {
        $tag = 'n'.(++self::$compareSeq);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');

        $longTy = $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false);
        $doubleTy = $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false);
        $stringTy = $i8->constInt(JITVariable::TYPE_STRING, false);

        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTy);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTy);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);

        $longBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_long');
        $checkDouble = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_chk_dbl');
        $doubleBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_dbl');
        $checkString = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_chk_str');
        $stringBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_str');
        $zeroBlock = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_zero');
        $done = BasicBlockHelper::append($context, 'jit_min_max_'.$tag.'_done');

        $context->builder->branchIf($isLong, $longBlock, $checkDouble);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longAsDouble = $context->builder->sitofp($longVal, $double);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkDouble);
        $context->builder->branchIf($isDouble, $doubleBlock, $checkString);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkString);
        $context->builder->branchIf($isString, $stringBlock, $zeroBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strMap = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $strMap['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'jit_min_max_'.$tag.'_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $stringAsDouble = $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($zeroBlock);
        $zero = $double->constReal(0.0);
        $zeroEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($double, 'jit_min_max_'.$tag.'_phi');
        $phi->addIncoming($longAsDouble, $longEnd);
        $phi->addIncoming($doubleVal, $doubleEnd);
        $phi->addIncoming($stringAsDouble, $stringEnd);
        $phi->addIncoming($zero, $zeroEnd);

        return $phi;
    }
}
