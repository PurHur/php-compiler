<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * php-src numeric-string classification as one module function.
 *
 * `i32 __phpc_numeric_str(__string__* s, __value__* out)` follows
 * Zend/zend_operators.c `_is_numeric_string_ex` (PHP 8 "saner numeric strings"):
 * optional leading whitespace, sign, digits, optional fraction, optional exponent,
 * optional trailing whitespace. Integer-shaped strings that fit in zend_long become
 * IS_LONG, everything else numeric IS_DOUBLE (zend_strtod — libc strtod here, same
 * result for the decimal forms this accepts).
 *
 * Returns {@see self::NUMERIC} (whole string numeric), {@see self::LEADING} (numeric
 * prefix followed by garbage — callers emit "A non-numeric value encountered") or
 * {@see self::NON_NUMERIC} (out untouched — callers throw the operator's TypeError).
 */
final class NumericStringLlvm
{
    public const FN = '__phpc_numeric_str';

    public const NUMERIC = 0;

    public const LEADING = 1;

    public const NON_NUMERIC = 2;

    /** @return Value i32 status */
    public static function call(Context $context, Value $str, Value $out): Value
    {
        return $context->builder->call(self::ensure($context), $str, $out);
    }

    private static function ensure(Context $context): Value\Function_
    {
        $fn = $context->module->getNamedFunction(self::FN);
        if ($fn instanceof Value\Function_ && $fn->countBasicBlocks() > 0) {
            return $fn;
        }
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        if (!$fn instanceof Value\Function_) {
            $fn = $context->module->addFunction(
                self::FN,
                $context->context->functionType($i32, false, $strPtr, $valuePtr)
            );
            $context->llvm->lib->LLVMSetLinkage($fn->value, \llvm\llvm::LLVMInternalLinkage);
        }
        $strtod = self::strtodFn($context);
        $writeLong = $context->lookupFunction('__value__writeLong');
        $writeDouble = $context->lookupFunction('__value__writeDouble');
        $smul = self::overflowIntrinsic($context, 'llvm.smul.with.overflow.i64');
        $ssub = self::overflowIntrinsic($context, 'llvm.ssub.with.overflow.i64');

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $ch = static fn (string $c) => $i8->constInt(\ord($c), false);

        $entry = $fn->appendBasicBlock('entry');
        $b->positionAtEnd($entry);
        $str = $fn->getParam(0);
        $out = $fn->getParam(1);
        $data = $b->pointerCast($b->structGep($str, $map['value']), $i8p);
        $len = $b->load($b->structGep($str, $map['length']));
        $pos = $b->alloca($i64);
        $b->store($zero, $pos);

        // byte at index (caller guarantees idx < len)
        $at = static fn (Value $idx): Value => $b->load($b->gep($data, $idx));
        $isWs = static function (Value $c) use ($b, $ch): Value {
            $r = $b->icmp(Builder::INT_EQ, $c, $ch(' '));
            foreach (["\t", "\n", "\r", "\v", "\f"] as $w) {
                $r = $b->bitwiseOr($r, $b->icmp(Builder::INT_EQ, $c, $ch($w)));
            }

            return $r;
        };
        $isDigit = static fn (Value $c): Value => $b->bitwiseAnd(
            $b->icmp(Builder::INT_UGE, $c, $ch('0')),
            $b->icmp(Builder::INT_ULE, $c, $ch('9'))
        );
        // while (pos < len && pred(byte)) ++pos;
        $skip = static function (string $tag, callable $pred) use ($fn, $b, $pos, $len, $at, $one): void {
            $head = $fn->appendBasicBlock($tag.'_head');
            $test = $fn->appendBasicBlock($tag.'_test');
            $step = $fn->appendBasicBlock($tag.'_step');
            $exit = $fn->appendBasicBlock($tag.'_exit');
            $b->branch($head);
            $b->positionAtEnd($head);
            $p = $b->load($pos);
            $b->branchIf($b->icmp(Builder::INT_ULT, $p, $len), $test, $exit);
            $b->positionAtEnd($test);
            $b->branchIf($pred($at($p)), $step, $exit);
            $b->positionAtEnd($step);
            $b->store($b->add($p, $one), $pos);
            $b->branch($head);
            $b->positionAtEnd($exit);
        };
        // if (pos < len && pred(byte)) ++pos;
        $optional = static function (string $tag, callable $pred) use ($fn, $b, $pos, $len, $at, $one): void {
            $test = $fn->appendBasicBlock($tag.'_test');
            $step = $fn->appendBasicBlock($tag.'_step');
            $exit = $fn->appendBasicBlock($tag.'_exit');
            $p = $b->load($pos);
            $b->branchIf($b->icmp(Builder::INT_ULT, $p, $len), $test, $exit);
            $b->positionAtEnd($test);
            $b->branchIf($pred($at($p)), $step, $exit);
            $b->positionAtEnd($step);
            $b->store($b->add($p, $one), $pos);
            $b->branch($exit);
            $b->positionAtEnd($exit);
        };

        $skip('lead_ws', $isWs);
        $start = $b->load($pos);
        $optional('sign', static fn (Value $c): Value => $b->bitwiseOr(
            $b->icmp(Builder::INT_EQ, $c, $ch('+')),
            $b->icmp(Builder::INT_EQ, $c, $ch('-'))
        ));
        $digitsStart = $b->load($pos);
        $skip('int_digits', $isDigit);
        $intEnd = $b->load($pos);
        $hasInt = $b->icmp(Builder::INT_UGT, $intEnd, $digitsStart);
        $isDoubleSlot = $b->alloca($context->getTypeFromString('int1'));
        $b->store($context->getTypeFromString('int1')->constInt(0, false), $isDoubleSlot);

        // fraction: '.' digits* — valid when there are digits on either side
        $fracTest = $fn->appendBasicBlock('frac_test');
        $fracScan = $fn->appendBasicBlock('frac_scan');
        $afterFrac = $fn->appendBasicBlock('after_frac');
        $b->branchIf($b->icmp(Builder::INT_ULT, $intEnd, $len), $fracTest, $afterFrac);
        $b->positionAtEnd($fracTest);
        $b->branchIf($b->icmp(Builder::INT_EQ, $at($intEnd), $ch('.')), $fracScan, $afterFrac);
        $b->positionAtEnd($fracScan);
        $b->store($b->add($intEnd, $one), $pos);
        $skip('frac_digits', $isDigit);
        $fracEnd = $b->load($pos);
        $fracDigits = $b->icmp(Builder::INT_UGT, $fracEnd, $b->add($intEnd, $one));
        $fracOk = $b->bitwiseOr($hasInt, $fracDigits);
        $fracAccept = $fn->appendBasicBlock('frac_accept');
        $fracReject = $fn->appendBasicBlock('frac_reject');
        $b->branchIf($fracOk, $fracAccept, $fracReject);
        $b->positionAtEnd($fracAccept);
        $b->store($context->getTypeFromString('int1')->constInt(1, false), $isDoubleSlot);
        $b->branch($afterFrac);
        $b->positionAtEnd($fracReject);
        $b->store($intEnd, $pos);
        $b->branch($afterFrac);

        $b->positionAtEnd($afterFrac);
        $mantEnd = $b->load($pos);
        $isDouble = $b->load($isDoubleSlot);
        $anyMantissa = $b->bitwiseOr($hasInt, $isDouble);
        $nonNumeric = $fn->appendBasicBlock('non_numeric');
        $expCheck = $fn->appendBasicBlock('exp_check');
        $b->branchIf($anyMantissa, $expCheck, $nonNumeric);

        $b->positionAtEnd($nonNumeric);
        $b->returnValue($i32->constInt(self::NON_NUMERIC, false));

        // exponent: [eE] [+-]? digits+ — only consumed when digits follow
        $b->positionAtEnd($expCheck);
        $expTest = $fn->appendBasicBlock('exp_test');
        $expScan = $fn->appendBasicBlock('exp_scan');
        $afterExp = $fn->appendBasicBlock('after_exp');
        $b->branchIf($b->icmp(Builder::INT_ULT, $mantEnd, $len), $expTest, $afterExp);
        $b->positionAtEnd($expTest);
        $e = $at($mantEnd);
        $b->branchIf(
            $b->bitwiseOr($b->icmp(Builder::INT_EQ, $e, $ch('e')), $b->icmp(Builder::INT_EQ, $e, $ch('E'))),
            $expScan,
            $afterExp
        );
        $b->positionAtEnd($expScan);
        $b->store($b->add($mantEnd, $one), $pos);
        $optional('exp_sign', static fn (Value $c): Value => $b->bitwiseOr(
            $b->icmp(Builder::INT_EQ, $c, $ch('+')),
            $b->icmp(Builder::INT_EQ, $c, $ch('-'))
        ));
        $expDigitsStart = $b->load($pos);
        $skip('exp_digits', $isDigit);
        $expEnd = $b->load($pos);
        $expAccept = $fn->appendBasicBlock('exp_accept');
        $expReject = $fn->appendBasicBlock('exp_reject');
        $b->branchIf($b->icmp(Builder::INT_UGT, $expEnd, $expDigitsStart), $expAccept, $expReject);
        $b->positionAtEnd($expAccept);
        $b->store($context->getTypeFromString('int1')->constInt(1, false), $isDoubleSlot);
        $b->branch($afterExp);
        $b->positionAtEnd($expReject);
        $b->store($mantEnd, $pos);
        $b->branch($afterExp);

        $b->positionAtEnd($afterExp);
        $numEnd = $b->load($pos);
        $skip('trail_ws', $isWs);
        $status = $b->select(
            $b->icmp(Builder::INT_EQ, $b->load($pos), $len),
            $i32->constInt(self::NUMERIC, false),
            $i32->constInt(self::LEADING, false)
        );
        $longPath = $fn->appendBasicBlock('long_path');
        $doublePath = $fn->appendBasicBlock('double_path');
        $b->branchIf($b->load($isDoubleSlot), $doublePath, $longPath);

        // Accumulate negatively so ZEND_LONG_MIN parses; overflow → double (zend_strtod).
        $b->positionAtEnd($longPath);
        $accSlot = $b->alloca($i64);
        $idxSlot = $b->alloca($i64);
        $b->store($zero, $accSlot);
        $b->store($digitsStart, $idxSlot);
        $accHead = $fn->appendBasicBlock('acc_head');
        $accBody = $fn->appendBasicBlock('acc_body');
        $accMulOk = $fn->appendBasicBlock('acc_mul_ok');
        $accStep = $fn->appendBasicBlock('acc_step');
        $accDone = $fn->appendBasicBlock('acc_done');
        $b->branch($accHead);
        $b->positionAtEnd($accHead);
        $idx = $b->load($idxSlot);
        $b->branchIf($b->icmp(Builder::INT_ULT, $idx, $intEnd), $accBody, $accDone);
        $b->positionAtEnd($accBody);
        $digit = $b->zExt($b->sub($at($idx), $ch('0')), $i64);
        $mul = $b->call($smul, $b->load($accSlot), $i64->constInt(10, false));
        $b->branchIf($b->extractValue($mul, 1), $doublePath, $accMulOk);
        $b->positionAtEnd($accMulOk);
        $sub = $b->call($ssub, $b->extractValue($mul, 0), $digit);
        $b->branchIf($b->extractValue($sub, 1), $doublePath, $accStep);
        $b->positionAtEnd($accStep);
        $b->store($b->extractValue($sub, 0), $accSlot);
        $b->store($b->add($idx, $one), $idxSlot);
        $b->branch($accHead);

        $b->positionAtEnd($accDone);
        $acc = $b->load($accSlot);
        $negative = $b->icmp(
            Builder::INT_EQ,
            $b->select($b->icmp(Builder::INT_UGT, $digitsStart, $start), $at($start), $ch('+')),
            $ch('-')
        );
        $negWrite = $fn->appendBasicBlock('neg_write');
        $posCheck = $fn->appendBasicBlock('pos_check');
        $posWrite = $fn->appendBasicBlock('pos_write');
        $b->branchIf($negative, $negWrite, $posCheck);
        $b->positionAtEnd($negWrite);
        $b->call($writeLong, $out, $acc);
        $b->returnValue($status);
        $b->positionAtEnd($posCheck);
        $minLong = $i64->constInt(\PHP_INT_MIN, true);
        $b->branchIf($b->icmp(Builder::INT_EQ, $acc, $minLong), $doublePath, $posWrite);
        $b->positionAtEnd($posWrite);
        $b->call($writeLong, $out, $b->sub($zero, $acc));
        $b->returnValue($status);

        // strtod stops at the first byte it cannot use, which is at or before numEnd for
        // every form accepted above (no hex/inf/nan can reach here).
        $b->positionAtEnd($doublePath);
        $d = $b->call($strtod, $b->gep($data, $start), $context->getTypeFromString('int8**')->constNull());
        $b->call($writeDouble, $out, $d);
        $b->returnValue($status);

        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        } else {
            $b->clearInsertionPosition();
        }

        return $fn;
    }

    private static function strtodFn(Context $context): Value\Function_
    {
        $fn = $context->module->getNamedFunction('strtod');
        if ($fn instanceof Value\Function_) {
            return $fn;
        }

        return $context->module->addFunction('strtod', $context->context->functionType(
            $context->getTypeFromString('double'),
            false,
            $context->getTypeFromString('int8*'),
            $context->getTypeFromString('int8**')
        ));
    }

    private static function overflowIntrinsic(Context $context, string $name): Value\Function_
    {
        $fn = $context->module->getNamedFunction($name);
        if ($fn instanceof Value\Function_) {
            return $fn;
        }
        $i64 = $context->getTypeFromString('int64');
        $ret = $context->context->structType(false, $i64, $context->getTypeFromString('int1'));

        return $context->module->addFunction($name, $context->context->functionType($ret, false, $i64, $i64));
    }
}
