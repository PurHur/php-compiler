<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * php-src zend_compare() for scalar value boxes, as one module function.
 *
 * `i64 __phpc_zend_compare(__value__* a, __value__* b)` returns -1/0/1 exactly as
 * Zend/zend_operators.c (PHP 8.2) zend_compare does for every pair of null, bool, int,
 * float and string — including compare_long_to_string / compare_double_to_string (a
 * non-numeric string compares against the number's string form), zendi_smart_strcmp
 * (numeric strings compare numerically, with php-src's overflow rules) and
 * ZEND_NORMALIZE_BOOL's treatment of NaN differences as 1. Arrays and objects go to the
 * existing __value__spaceship runtime.
 */
final class ZendCompareLlvm
{
    public const FN = '__phpc_zend_compare';

    // zend_uchar type codes, in php-src's order (IS_NULL < IS_FALSE < IS_TRUE ...)
    private const Z_OTHER = 0;
    private const Z_NULL = 1;
    private const Z_FALSE = 2;
    private const Z_TRUE = 3;
    private const Z_LONG = 4;
    private const Z_DOUBLE = 5;
    private const Z_STRING = 6;

    private Context $context;
    private Builder $b;
    private Value\Function_ $fn;
    /** @var \PHPLLVM\Type */
    private $i64;
    private Value $out;

    /** @return Value i64 in {-1, 0, 1} */
    public static function call(Context $context, Value $a, Value $b): Value
    {
        $valuePtr = $context->getTypeFromString('__value__*');

        return $context->builder->call(
            self::ensure($context),
            $context->builder->pointerCast($a, $valuePtr),
            $context->builder->pointerCast($b, $valuePtr)
        );
    }

    /**
     * zend_binary_strcmp(s1, s2) normalized to -1/0/1, emitted at the current position.
     *
     * @return Value i64
     */
    public static function emitBinaryStrcmp(Context $context, Value $s1, Value $s2): Value
    {
        $fn = BasicBlockHelper::parentFunction($context);

        return (new self($context, $fn))->binaryStrcmp($s1, $s2);
    }

    private static function ensure(Context $context): Value\Function_
    {
        $fn = $context->module->getNamedFunction(self::FN);
        if ($fn instanceof Value\Function_ && $fn->countBasicBlocks() > 0) {
            return $fn;
        }
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        if (!$fn instanceof Value\Function_) {
            $fn = $context->module->addFunction(
                self::FN,
                $context->context->functionType($i64, false, $valuePtr, $valuePtr)
            );
            $context->llvm->lib->LLVMSetLinkage($fn->value, \llvm\llvm::LLVMInternalLinkage);
        }
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        (new self($context, $fn))->emitBody();
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        } else {
            $context->builder->clearInsertionPosition();
        }

        return $fn;
    }

    private function __construct(Context $context, Value\Function_ $fn)
    {
        $this->context = $context;
        $this->b = $context->builder;
        $this->fn = $fn;
        $this->i64 = $context->getTypeFromString('int64');
    }

    private function blk(string $name): \PHPLLVM\BasicBlock
    {
        return $this->fn->appendBasicBlock($name);
    }

    private function c(int $v): Value
    {
        return $this->i64->constInt($v, $v < 0);
    }

    private function ret(Value $v): void
    {
        $this->b->returnValue($this->b->intCast($v, $this->i64));
    }

    private function emitBody(): void
    {
        $b = $this->b;
        $b->positionAtEnd($this->blk('entry'));
        $a = $this->fn->getParam(0);
        $bb = $this->fn->getParam(1);
        $valueTy = $this->context->getTypeFromString('__value__');
        $numA = $b->alloca($valueTy);
        $numB = $b->alloca($valueTy);
        // Parse targets must start as IS_NULL: __value__write* releases the old contents.
        $typeIdx = $this->context->structFieldMap['__value__']['type'];
        $nullTag = $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false);
        $b->store($nullTag, $b->structGep($numA, $typeIdx));
        $b->store($nullTag, $b->structGep($numB, $typeIdx));
        $za = $this->zendType($a);
        $zb = $this->zendType($bb);
        $isZ = fn (Value $z, int $t): Value => $b->icmp(Builder::INT_EQ, $z, $this->c($t));
        $pair = fn (int $x, int $y): Value => $b->bitwiseAnd($isZ($za, $x), $isZ($zb, $y));

        $dispatch = function (Value $cond, string $name, callable $emit) use ($b): void {
            $yes = $this->blk($name);
            $no = $this->blk($name.'_no');
            $b->branchIf($cond, $yes, $no);
            $b->positionAtEnd($yes);
            $emit();
            $b->positionAtEnd($no);
        };

        $dispatch(
            $b->bitwiseOr($isZ($za, self::Z_OTHER), $isZ($zb, self::Z_OTHER)),
            'other',
            fn () => $this->ret(SpaceshipRuntime::callValueSpaceship($this->context, $a, $bb))
        );
        $dispatch($pair(self::Z_LONG, self::Z_LONG), 'long_long', function () use ($a, $bb): void {
            $this->ret($this->sign($this->readLong($a), $this->readLong($bb)));
        });
        $dispatch($pair(self::Z_DOUBLE, self::Z_DOUBLE), 'dbl_dbl', function () use ($a, $bb): void {
            $da = $this->readDouble($a);
            $db = $this->readDouble($bb);
            $this->ret($this->b->select(
                $this->b->fcmp(Builder::REAL_OEQ, $da, $db),
                $this->c(0),
                $this->normalizeDouble($this->b->fSub($da, $db))
            ));
        });
        $dispatch($b->bitwiseOr($pair(self::Z_LONG, self::Z_DOUBLE), $pair(self::Z_DOUBLE, self::Z_LONG)), 'mixed_num', function () use ($a, $bb): void {
            $this->ret($this->normalizeDouble($this->b->fSub($this->numberAsDouble($a), $this->numberAsDouble($bb))));
        });
        $dispatch($pair(self::Z_STRING, self::Z_STRING), 'str_str', function () use ($a, $bb, $numA, $numB): void {
            $sa = $this->readString($a);
            $sb = $this->readString($bb);
            $same = $this->blk('str_same');
            $diff = $this->blk('str_diff');
            $this->b->branchIf($this->b->icmp(Builder::INT_EQ, $sa, $sb), $same, $diff);
            $this->b->positionAtEnd($same);
            $this->ret($this->c(0));
            $this->b->positionAtEnd($diff);
            $this->ret($this->smartStrcmp($sa, $sb, $numA, $numB));
        });
        $dispatch($pair(self::Z_NULL, self::Z_STRING), 'null_str', function () use ($bb): void {
            $len = $this->strlen($this->readString($bb));
            $this->ret($this->b->select($this->b->icmp(Builder::INT_EQ, $len, $this->c(0)), $this->c(0), $this->c(-1)));
        });
        $dispatch($pair(self::Z_STRING, self::Z_NULL), 'str_null', function () use ($a): void {
            $len = $this->strlen($this->readString($a));
            $this->ret($this->b->select($this->b->icmp(Builder::INT_EQ, $len, $this->c(0)), $this->c(0), $this->c(1)));
        });
        $dispatch($pair(self::Z_LONG, self::Z_STRING), 'long_str', function () use ($a, $bb, $numB): void {
            $this->ret($this->compareLongToString($this->readLong($a), $this->readString($bb), $numB));
        });
        $dispatch($pair(self::Z_STRING, self::Z_LONG), 'str_long', function () use ($a, $bb, $numA): void {
            $this->ret($this->b->sub($this->c(0), $this->compareLongToString($this->readLong($bb), $this->readString($a), $numA)));
        });
        $dispatch($pair(self::Z_DOUBLE, self::Z_STRING), 'dbl_str', function () use ($a, $bb, $numB): void {
            $d = $this->readDouble($a);
            $this->ret($this->b->select(
                $this->isNan($d),
                $this->c(1),
                $this->compareDoubleToString($d, $this->readString($bb), $numB)
            ));
        });
        $dispatch($pair(self::Z_STRING, self::Z_DOUBLE), 'str_dbl', function () use ($a, $bb, $numA): void {
            $d = $this->readDouble($bb);
            $this->ret($this->b->select(
                $this->isNan($d),
                $this->c(1),
                $this->b->sub($this->c(0), $this->compareDoubleToString($d, $this->readString($a), $numA))
            ));
        });

        // Remaining pairs involve null/false/true: zend_compare's bool fallback.
        $ta = $this->isTrue($a, $za);
        $tb = $this->isTrue($bb, $zb);
        $zero = $this->c(0);
        $one = $this->c(1);
        $neg = $this->c(-1);
        $r = $b->select(
            $b->icmp(Builder::INT_SLT, $za, $this->c(self::Z_TRUE)),
            $b->select($tb, $neg, $zero),
            $b->select(
                $isZ($za, self::Z_TRUE),
                $b->select($tb, $zero, $one),
                $b->select(
                    $b->icmp(Builder::INT_SLT, $zb, $this->c(self::Z_TRUE)),
                    $b->select($ta, $one, $zero),
                    $b->select($ta, $zero, $neg)
                )
            )
        );
        $this->ret($r);
    }

    private function zendType(Value $v): Value
    {
        $b = $this->b;
        $i8 = $this->context->getTypeFromString('int8');
        $map = $this->context->structFieldMap['__value__'];
        $kind = $b->bitwiseAnd($b->load($b->structGep($v, $map['type'])), $i8->constInt(0x7f, false));
        $boolByte = $b->load($b->pointerCast($b->structGep($v, $map['value']), $this->context->getTypeFromString('int8*')));
        $isKind = fn (int $t): Value => $b->icmp(Builder::INT_EQ, $kind, $i8->constInt($t, false));
        $z = $this->c(self::Z_OTHER);
        $z = $b->select($isKind(Variable::TYPE_STRING & 0x7f), $this->c(self::Z_STRING), $z);
        $z = $b->select($isKind(Variable::TYPE_NATIVE_DOUBLE), $this->c(self::Z_DOUBLE), $z);
        $z = $b->select($isKind(Variable::TYPE_NATIVE_LONG), $this->c(self::Z_LONG), $z);
        $z = $b->select(
            $isKind(Variable::TYPE_NATIVE_BOOL),
            $b->select($b->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false)), $this->c(self::Z_TRUE), $this->c(self::Z_FALSE)),
            $z
        );

        return $b->select($isKind(Variable::TYPE_NULL), $this->c(self::Z_NULL), $z);
    }

    private function readLong(Value $v): Value
    {
        return $this->b->call($this->context->lookupFunction('__value__readLong'), $v);
    }

    private function readDouble(Value $v): Value
    {
        return $this->b->call($this->context->lookupFunction('__value__readDouble'), $v);
    }

    private function readString(Value $v): Value
    {
        return $this->b->call($this->context->lookupFunction('__value__readString'), $v);
    }

    /** IS_LONG or IS_DOUBLE box as double. */
    private function numberAsDouble(Value $v): Value
    {
        return $this->b->call($this->context->lookupFunction('__value__readDouble'), $v);
    }

    private function strlen(Value $s): Value
    {
        return $this->b->load($this->b->structGep($s, $this->context->structFieldMap['__string__']['length']));
    }

    private function sign(Value $x, Value $y): Value
    {
        $b = $this->b;

        return $b->sub(
            $b->zExt($b->icmp(Builder::INT_SGT, $x, $y), $this->i64),
            $b->zExt($b->icmp(Builder::INT_SLT, $x, $y), $this->i64)
        );
    }

    /** ZEND_NORMALIZE_BOOL(d): NaN is truthy and not < 0, so it normalizes to 1. */
    private function normalizeDouble(Value $d): Value
    {
        $b = $this->b;
        $zero = $this->context->getTypeFromString('double')->constReal(0.0);

        return $b->select(
            $b->fcmp(Builder::REAL_OEQ, $d, $zero),
            $this->c(0),
            $b->select($b->fcmp(Builder::REAL_OLT, $d, $zero), $this->c(-1), $this->c(1))
        );
    }

    private function isNan(Value $d): Value
    {
        return $this->b->fcmp(Builder::REAL_UNO, $d, $d);
    }

    /** zval_is_true for null/bool/int/float/string boxes. */
    private function isTrue(Value $v, Value $z): Value
    {
        $b = $this->b;
        $isZ = fn (int $t): Value => $b->icmp(Builder::INT_EQ, $z, $this->c($t));
        $long = $b->icmp(Builder::INT_NE, $this->readLong($v), $this->c(0));
        $dbl = $b->fcmp(Builder::REAL_UNE, $this->readDouble($v), $this->context->getTypeFromString('double')->constReal(0.0));
        // Strings: only read the payload when the box is a string.
        $strBlock = $this->blk('is_true_str');
        $join = $this->blk('is_true_join');
        $from = $b->getInsertBlock();
        $b->branchIf($isZ(self::Z_STRING), $strBlock, $join);
        $b->positionAtEnd($strBlock);
        $s = $this->readString($v);
        $len = $this->strlen($s);
        $first = $b->load($b->pointerCast(
            $b->structGep($s, $this->context->structFieldMap['__string__']['value']),
            $this->context->getTypeFromString('int8*')
        ));
        $strTrue = $b->bitwiseOr(
            $b->icmp(Builder::INT_UGT, $len, $this->c(1)),
            $b->bitwiseAnd(
                $b->icmp(Builder::INT_EQ, $len, $this->c(1)),
                $b->icmp(Builder::INT_NE, $first, $this->context->getTypeFromString('int8')->constInt(\ord('0'), false))
            )
        );
        $strEnd = $b->getInsertBlock();
        $b->branch($join);
        $b->positionAtEnd($join);
        $i1 = $this->context->getTypeFromString('int1');
        $strPhi = $b->phi($i1);
        $strPhi->addIncoming($i1->constInt(0, false), $from);
        $strPhi->addIncoming($strTrue, $strEnd);

        return $b->select(
            $isZ(self::Z_TRUE),
            $i1->constInt(1, false),
            $b->select(
                $isZ(self::Z_LONG),
                $long,
                $b->select($isZ(self::Z_DOUBLE), $dbl, $b->select($isZ(self::Z_STRING), $strPhi, $i1->constInt(0, false)))
            )
        );
    }

    /** zend_binary_strcmp, normalized. */
    private function binaryStrcmp(Value $s1, Value $s2): Value
    {
        $b = $this->b;
        $map = $this->context->structFieldMap['__string__'];
        $i8p = $this->context->getTypeFromString('int8*');
        $l1 = $this->strlen($s1);
        $l2 = $this->strlen($s2);
        $min = $b->select($b->icmp(Builder::INT_ULT, $l1, $l2), $l1, $l2);
        $memcmp = $this->memcmpFn();
        $r = $b->call(
            $memcmp,
            $b->pointerCast($b->structGep($s1, $map['value']), $i8p),
            $b->pointerCast($b->structGep($s2, $map['value']), $i8p),
            $min
        );
        $r64 = $b->sExt($r, $this->i64);

        return $b->select(
            $b->icmp(Builder::INT_NE, $r64, $this->c(0)),
            $this->sign($r64, $this->c(0)),
            $this->sign($l1, $l2)
        );
    }

    /** @return array{Value, Value} [status i32, type byte of the parsed box] */
    private function parseNumeric(Value $s, Value $box): array
    {
        $status = NumericStringLlvm::call($this->context, $s, $box);
        $map = $this->context->structFieldMap['__value__'];

        return [$status, $this->b->load($this->b->structGep($box, $map['type']))];
    }

    private function statusIsNumeric(Value $status): Value
    {
        $b = $this->b;
        $i32 = $this->context->getTypeFromString('int32');

        return $b->bitwiseOr(
            $b->icmp(Builder::INT_EQ, $status, $i32->constInt(NumericStringLlvm::NUMERIC, false)),
            $b->icmp(Builder::INT_UGE, $status, $i32->constInt(NumericStringLlvm::NUMERIC_OFLOW_POS, false))
        );
    }

    private function boxIsLong(Value $typeByte): Value
    {
        return $this->b->icmp(
            Builder::INT_EQ,
            $typeByte,
            $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
    }

    /** compare_long_to_string() */
    private function compareLongToString(Value $l, Value $s, Value $box): Value
    {
        $b = $this->b;
        [$status, $type] = $this->parseNumeric($s, $box);
        $numeric = $this->blk('lts_numeric');
        $textual = $this->blk('lts_textual');
        $join = $this->blk('lts_join');
        $b->branchIf($this->statusIsNumeric($status), $numeric, $textual);

        $b->positionAtEnd($numeric);
        $numRes = $b->select(
            $this->boxIsLong($type),
            $this->sign($l, $this->readLong($box)),
            $this->normalizeDouble($b->fSub($b->siToFp($l, $this->context->getTypeFromString('double')), $this->readDouble($box)))
        );
        $numEnd = $b->getInsertBlock();
        $b->branch($join);

        $b->positionAtEnd($textual);
        $str = $b->call($this->context->lookupFunction('__string__fromLong'), $l);
        $txtRes = $this->binaryStrcmp($str, $s);
        $this->release($str);
        $txtEnd = $b->getInsertBlock();
        $b->branch($join);

        $b->positionAtEnd($join);
        $phi = $b->phi($this->i64);
        $phi->addIncoming($numRes, $numEnd);
        $phi->addIncoming($txtRes, $txtEnd);

        return $phi;
    }

    /** compare_double_to_string() */
    private function compareDoubleToString(Value $d, Value $s, Value $box): Value
    {
        $b = $this->b;
        $f64 = $this->context->getTypeFromString('double');
        [$status, $type] = $this->parseNumeric($s, $box);
        $numeric = $this->blk('dts_numeric');
        $textual = $this->blk('dts_textual');
        $join = $this->blk('dts_join');
        $b->branchIf($this->statusIsNumeric($status), $numeric, $textual);

        $b->positionAtEnd($numeric);
        $sd = $b->select($this->boxIsLong($type), $b->siToFp($this->readLong($box), $f64), $this->readDouble($box));
        // d is never NaN here (checked by the caller), so d == sd normalizes to 0 already.
        $numRes = $this->normalizeDouble($b->fSub($d, $sd));
        $numEnd = $b->getInsertBlock();
        $b->branch($join);

        $b->positionAtEnd($textual);
        $str = ZendDoubleStringRuntime::format($this->context, $d);
        $txtRes = $this->binaryStrcmp($str, $s);
        $txtEnd = $b->getInsertBlock();
        $b->branch($join);

        $b->positionAtEnd($join);
        $phi = $b->phi($this->i64);
        $phi->addIncoming($numRes, $numEnd);
        $phi->addIncoming($txtRes, $txtEnd);

        return $phi;
    }

    /** zendi_smart_strcmp() for two distinct strings. */
    private function smartStrcmp(Value $s1, Value $s2, Value $box1, Value $box2): Value
    {
        $b = $this->b;
        $f64 = $this->context->getTypeFromString('double');
        $i32 = $this->context->getTypeFromString('int32');
        $out = $b->alloca($this->i64);
        $done = $this->blk('ss_done');
        $strcmp = $this->blk('ss_strcmp');
        $second = $this->blk('ss_second');
        $both = $this->blk('ss_both');

        [$st1, $t1] = $this->parseNumeric($s1, $box1);
        $b->branchIf($this->statusIsNumeric($st1), $second, $strcmp);
        $b->positionAtEnd($second);
        [$st2, $t2] = $this->parseNumeric($s2, $box2);
        $b->branchIf($this->statusIsNumeric($st2), $both, $strcmp);

        $b->positionAtEnd($both);
        $oflow = fn (Value $st): Value => $b->select(
            $b->icmp(Builder::INT_EQ, $st, $i32->constInt(NumericStringLlvm::NUMERIC_OFLOW_POS, false)),
            $this->c(1),
            $b->select($b->icmp(Builder::INT_EQ, $st, $i32->constInt(NumericStringLlvm::NUMERIC_OFLOW_NEG, false)), $this->c(-1), $this->c(0))
        );
        $o1 = $oflow($st1);
        $o2 = $oflow($st2);
        $isLong1 = $this->boxIsLong($t1);
        $isLong2 = $this->boxIsLong($t2);
        $d1 = $b->select($isLong1, $b->siToFp($this->readLong($box1), $f64), $this->readDouble($box1));
        $d2 = $b->select($isLong2, $b->siToFp($this->readLong($box2), $f64), $this->readDouble($box2));
        $zeroD = $f64->constReal(0.0);
        // Both integers overflowed to the same side with equal doubles: compare as strings.
        $sameOflow = $b->bitwiseAnd(
            $b->bitwiseAnd($b->icmp(Builder::INT_NE, $o1, $this->c(0)), $b->icmp(Builder::INT_EQ, $o1, $o2)),
            $b->fcmp(Builder::REAL_OEQ, $b->fSub($d1, $d2), $zeroD)
        );
        $num = $this->blk('ss_num');
        $b->branchIf($sameOflow, $strcmp, $num);

        $b->positionAtEnd($num);
        $longs = $this->blk('ss_longs');
        $dbls = $this->blk('ss_dbls');
        $b->branchIf($b->bitwiseAnd($isLong1, $isLong2), $longs, $dbls);
        $b->positionAtEnd($longs);
        $b->store($this->sign($this->readLong($box1), $this->readLong($box2)), $out);
        $b->branch($done);

        $b->positionAtEnd($dbls);
        $only2Double = $this->blk('ss_only2');
        $not2 = $this->blk('ss_not_only2');
        $b->branchIf($isLong1, $only2Double, $not2);
        // ret1 != IS_DOUBLE: oflow2 ? -oflow2 : numeric
        $b->positionAtEnd($only2Double);
        $diff = $this->blk('ss_diff');
        $retO2 = $this->blk('ss_ret_o2');
        $b->branchIf($b->icmp(Builder::INT_NE, $o2, $this->c(0)), $retO2, $diff);
        $b->positionAtEnd($retO2);
        $b->store($b->sub($this->c(0), $o2), $out);
        $b->branch($done);
        $b->positionAtEnd($not2);
        $only1Double = $this->blk('ss_only1');
        $bothDouble = $this->blk('ss_both_double');
        $b->branchIf($isLong2, $only1Double, $bothDouble);
        $b->positionAtEnd($only1Double);
        $retO1 = $this->blk('ss_ret_o1');
        $b->branchIf($b->icmp(Builder::INT_NE, $o1, $this->c(0)), $retO1, $diff);
        $b->positionAtEnd($retO1);
        $b->store($o1, $out);
        $b->branch($done);
        // both doubles, equal and non-finite: compare as strings
        $b->positionAtEnd($bothDouble);
        $isFinite = $b->fcmp(Builder::REAL_OEQ, $b->fSub($d1, $d1), $zeroD);
        $b->branchIf(
            $b->bitwiseAnd($b->fcmp(Builder::REAL_OEQ, $d1, $d2), $b->xor($isFinite, $this->context->getTypeFromString('int1')->constInt(1, false))),
            $strcmp,
            $diff
        );
        $b->positionAtEnd($diff);
        $b->store($this->normalizeDouble($b->fSub($d1, $d2)), $out);
        $b->branch($done);

        $b->positionAtEnd($strcmp);
        $b->store($this->binaryStrcmp($s1, $s2), $out);
        $b->branch($done);

        $b->positionAtEnd($done);

        return $b->load($out);
    }

    private function release(Value $str): void
    {
        $this->b->call(
            $this->context->lookupFunction('__ref__delref'),
            $this->b->pointerCast($str, $this->context->getTypeFromString('__ref__virtual*'))
        );
    }

    private function memcmpFn(): Value\Function_
    {
        $fn = $this->context->module->getNamedFunction('memcmp');
        if ($fn instanceof Value\Function_) {
            return $fn;
        }
        $i8p = $this->context->getTypeFromString('int8*');

        return $this->context->module->addFunction('memcmp', $this->context->context->functionType(
            $this->context->getTypeFromString('int32'),
            false,
            $i8p,
            $i8p,
            $this->context->getTypeFromString('size_t')
        ));
    }
}
