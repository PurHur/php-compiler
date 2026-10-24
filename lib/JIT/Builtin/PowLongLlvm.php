<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * `int ** int` exactly as php-src computes it, as one module function.
 *
 * php-src Zend/zend_operators.c pow_function_base: for a non-negative exponent,
 * square-and-multiply in zend_long with ZEND_SIGNED_MULTIPLY_LONG overflow checks; on the
 * first overflow the result continues in double from that point
 * (`dval * pow(l2, i)` / `(double)l1 * pow(dval, i)`); a negative exponent is
 * `pow((double)base, (double)exp)`. Reproducing the exact overflow continuation is what
 * keeps float results bit-identical to Zend.
 *
 * `void __phpc_pow_long(__value__* out, i64 base, i64 exp)` writes IS_LONG or IS_DOUBLE.
 * With a compile-time exponent the optimizer unrolls the loop at the call site.
 */
final class PowLongLlvm
{
    public const FN = '__phpc_pow_long';

    public static function call(Context $context, Value $out, Value $base, Value $exp): void
    {
        $context->builder->call(self::ensure($context), $out, $base, $exp);
    }

    private static function ensure(Context $context): Value\Function_
    {
        $fn = $context->module->getNamedFunction(self::FN);
        if ($fn instanceof Value\Function_ && $fn->countBasicBlocks() > 0) {
            return $fn;
        }
        MathFpow::ensureLinked($context);
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        if (!$fn instanceof Value\Function_) {
            $fn = $context->module->addFunction(
                self::FN,
                $context->context->functionType($context->getTypeFromString('void'), false, $valuePtr, $i64, $i64)
            );
            $context->llvm->lib->LLVMSetLinkage($fn->value, \llvm\llvm::LLVMInternalLinkage);
        }
        $writeLong = $context->lookupFunction('__value__writeLong');
        $writeDouble = $context->lookupFunction('__value__writeDouble');
        $smul = self::smulWithOverflow($context);

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('entry');
        $negative = $fn->appendBasicBlock('negative');
        $nonNeg = $fn->appendBasicBlock('non_negative');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $odd = $fn->appendBasicBlock('odd');
        $oddOk = $fn->appendBasicBlock('odd_ok');
        $oddOv = $fn->appendBasicBlock('odd_overflow');
        $even = $fn->appendBasicBlock('even');
        $evenOk = $fn->appendBasicBlock('even_ok');
        $evenOv = $fn->appendBasicBlock('even_overflow');
        $retLong = $fn->appendBasicBlock('ret_long');

        $out = $fn->getParam(0);
        $base = $fn->getParam(1);
        $exp = $fn->getParam(2);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $b->positionAtEnd($entry);
        $b->branchIf($b->icmp(Builder::INT_SLT, $exp, $zero), $negative, $nonNeg);

        $b->positionAtEnd($negative);
        $b->call($writeDouble, $out, MathFpow::invoke($context, $b->siToFp($base, $f64), $b->siToFp($exp, $f64)));
        $b->returnVoid();

        // i == 0 → 1 (incl. 0 ** 0); base == 0 → 0. Both fall out of the loop below:
        // i == 0 skips it with l1 = 1; base == 0 multiplies down to 0 without overflow.
        $b->positionAtEnd($nonNeg);
        $b->branch($loopHead);

        $b->positionAtEnd($loopHead);
        $l1 = $b->phi($i64);
        $l2 = $b->phi($i64);
        $i = $b->phi($i64);
        $b->branchIf($b->icmp(Builder::INT_SGE, $i, $one), $loopBody, $retLong);

        $b->positionAtEnd($loopBody);
        $isOdd = $b->icmp(Builder::INT_NE, $b->bitwiseAnd($i, $one), $zero);
        $b->branchIf($isOdd, $odd, $even);

        // i odd: --i; l1 *= l2; overflow → dval * pow(l2, i)
        $b->positionAtEnd($odd);
        $iOdd = $b->sub($i, $one);
        $m1 = $b->call($smul, $l1, $l2);
        $b->branchIf($b->extractValue($m1, 1), $oddOv, $oddOk);
        $b->positionAtEnd($oddOv);
        $dval = $b->fmul($b->siToFp($l1, $f64), $b->siToFp($l2, $f64));
        $b->call($writeDouble, $out, $b->fmul(
            $dval,
            MathFpow::invoke($context, $b->siToFp($l2, $f64), $b->siToFp($iOdd, $f64))
        ));
        $b->returnVoid();
        $b->positionAtEnd($oddOk);
        $l1Next = $b->extractValue($m1, 0);
        $b->branch($loopHead);

        // i even: i /= 2; l2 *= l2; overflow → (double)l1 * pow(dval, i)
        $b->positionAtEnd($even);
        $iEven = $b->ashr($i, $one);
        $m2 = $b->call($smul, $l2, $l2);
        $b->branchIf($b->extractValue($m2, 1), $evenOv, $evenOk);
        $b->positionAtEnd($evenOv);
        $sq = $b->fmul($b->siToFp($l2, $f64), $b->siToFp($l2, $f64));
        $b->call($writeDouble, $out, $b->fmul(
            $b->siToFp($l1, $f64),
            MathFpow::invoke($context, $sq, $b->siToFp($iEven, $f64))
        ));
        $b->returnVoid();
        $b->positionAtEnd($evenOk);
        $l2Next = $b->extractValue($m2, 0);
        $b->branch($loopHead);

        $l1->addIncoming($one, $nonNeg);
        $l1->addIncoming($l1Next, $oddOk);
        $l1->addIncoming($l1, $evenOk);
        $l2->addIncoming($base, $nonNeg);
        $l2->addIncoming($l2, $oddOk);
        $l2->addIncoming($l2Next, $evenOk);
        $i->addIncoming($exp, $nonNeg);
        $i->addIncoming($iOdd, $oddOk);
        $i->addIncoming($iEven, $evenOk);

        $b->positionAtEnd($retLong);
        $b->call($writeLong, $out, $l1);
        $b->returnVoid();

        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        } else {
            $b->clearInsertionPosition();
        }

        return $fn;
    }

    private static function smulWithOverflow(Context $context): Value\Function_
    {
        $name = 'llvm.smul.with.overflow.i64';
        $fn = $context->module->getNamedFunction($name);
        if ($fn instanceof Value\Function_) {
            return $fn;
        }
        $i64 = $context->getTypeFromString('int64');
        $ret = $context->context->structType(false, $i64, $context->getTypeFromString('int1'));

        return $context->module->addFunction($name, $context->context->functionType($ret, false, $i64, $i64));
    }
}
