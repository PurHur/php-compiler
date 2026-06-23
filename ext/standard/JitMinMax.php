<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
                $pickMin ? Builder::REAL_OGT : Builder::REAL_OGE,
                $best,
                $candidate
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }

        return $best;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function reduceNumericBoxes(Context $context, bool $pickMin, array $args): Value
    {
        $double = $context->getTypeFromString('double');
        $useFloat = self::argsNeedFloatCompare($args);
        $best = self::toCompareDouble($context, JitValueBox::valuePtrFromVariable($context, $args[0]));
        foreach (\array_slice($args, 1) as $arg) {
            $candidate = self::toCompareDouble($context, JitValueBox::valuePtrFromVariable($context, $arg));
            $cmp = $context->builder->fcmp(
                $pickMin ? Builder::REAL_OGT : Builder::REAL_OGE,
                $best,
                $candidate
            );
            $best = $context->builder->select($cmp, $candidate, $best);
        }
        if ($useFloat) {
            return $best;
        }

        return $context->builder->fptosi($best, $context->getTypeFromString('int64'));
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function argsNeedFloatCompare(array $args): bool
    {
        foreach ($args as $arg) {
            if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
                return true;
            }
            if (JITVariable::TYPE_STRING === $arg->type || JitValueBox::isValueOperand($arg)) {
                return true;
            }
        }

        return false;
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
