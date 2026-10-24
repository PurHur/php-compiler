<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\NumericStringLlvm;
use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * usort() on a packed list with a user comparator, php-src exact.
 *
 * ext/standard/array.c php_usort -> zend_array_sort(arr, php_array_user_compare, renumber)
 * -> zend_sort ({@see ZendSortLlvm}) with php_array_user_compare_unstable:
 * the comparator's return goes through zval_get_long and ZEND_NORMALIZE_BOOL (so 0.5 is 0);
 * a bool return raises the "Returning bool from comparison function is deprecated"
 * E_DEPRECATED once per call, and `false` retries with swapped operands
 * (-ZEND_NORMALIZE_BOOL(zval_get_long(cmp(b, a)))); ties fall back to the original
 * position (RETURN_STABLE_SORT).
 *
 * The comparator may be a Closure (passed to the comparator function as its object
 * pointer, dispatched by its runtime target) or a user function resolved at compile time.
 * (Was: bubble sort capped at n² passes, double results by sign, no bool handling.)
 */
final class UsortPackedLlvm
{
    private const DEPRECATION = 'usort(): Returning bool from comparison function is deprecated, '
        .'return an integer less than, equal to, or greater than zero';

    public static function sortPackedWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'usort_packed_llvm_cont');
        $objPtr = self::closureObjectPtr($context, $closure);
        $closureCall = $closure->closureCall;
        $objTy = $context->getTypeFromString('__object__*');
        self::emitSort(
            $context,
            $ht,
            static function (Context $ctx, Value $extra, Variable $a, Variable $b) use ($closureCall, $objTy): Value {
                $rebound = new Variable($ctx, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $ctx->builder->pointerCast($extra, $objTy));
                $rebound->closureCall = $closureCall;

                return (new NestedClosureInvoke())->call($ctx, $rebound, $a, $b);
            },
            $objPtr,
            true
        );
    }

    /** usort() with a user function resolved at compile time (`usort($a, 'cmp')`). */
    public static function sortPackedWithFunction(Context $context, Value $ht, Call $function): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'usort_packed_fn_cont');
        self::emitSort(
            $context,
            $ht,
            static fn (Context $ctx, Value $extra, Variable $a, Variable $b): Value => JitValueBox::coerceToValuePtrForStore(
                $ctx,
                $function->call($ctx, $a, $b)
            ),
            null,
            false
        );
    }

    /**
     * @param callable(Context, Value, Variable, Variable): Value $invoke returns the comparator
     *        result as __value__*; runs inside the comparator function
     */
    private static function emitSort(Context $context, Value $ht, callable $invoke, ?Value $extra, bool $inline): void
    {
        $b = $context->builder;
        $map = $context->structFieldMap['__hashtable__'];
        $i8 = $context->getTypeFromString('int8');
        $flag = self::deprecationFlag($context);
        $b->store($i8->constInt(0, false), $flag);
        $values = $b->load($b->structGep($ht, $map['values']));
        $n = $b->load($b->structGep($ht, $map['numElements']));
        ZendSortLlvm::emit(
            $context,
            $values,
            $n,
            static fn (Value $x, Value $y, Value $extraIn): Value => self::emitCompare($context, $invoke, $extraIn, $x, $y, $flag),
            'usort',
            $extra,
            $inline
        );
    }

    /** php_array_user_compare_unstable, inside the comparator function. */
    private static function emitCompare(Context $context, callable $invoke, Value $extra, Value $x, Value $y, Value $flag): Value
    {
        $b = $context->builder;
        $fn = BasicBlockHelper::parentFunction($context);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $out = BasicBlockHelper::entryAlloca($context, $i64);
        $argBox = static function (Value $elem) use ($context): Variable {
            $slot = JitValueBox::alloc($context);
            JitValueBox::copyFromPointer($context, $slot, $elem);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        };
        $ret = $invoke($context, $extra, $argBox($x), $argBox($y));
        $kind = $b->and(
            $b->load($b->structGep($ret, $context->structFieldMap['__value__']['type'])),
            $i8->constInt(0x7f, false)
        );
        $isBool = $b->icmp(Builder::INT_EQ, $kind, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false));
        $boolPath = $fn->appendBasicBlock('usort_cmp_bool');
        $plain = $fn->appendBasicBlock('usort_cmp_plain');
        $done = $fn->appendBasicBlock('usort_cmp_done');
        $b->branchIf($isBool, $boolPath, $plain);

        $b->positionAtEnd($plain);
        $b->store(self::normalize($context, self::zvalGetLong($context, $ret)), $out);
        $b->branch($done);

        $b->positionAtEnd($boolPath);
        $warn = $fn->appendBasicBlock('usort_cmp_deprecate');
        $afterWarn = $fn->appendBasicBlock('usort_cmp_after_deprecate');
        $b->branchIf($b->icmp(Builder::INT_EQ, $b->load($flag), $i8->constInt(0, false)), $warn, $afterWarn);
        $b->positionAtEnd($warn);
        $b->store($i8->constInt(1, false), $flag);
        self::emitDeprecation($context);
        $b->branch($afterWarn);
        $b->positionAtEnd($afterWarn);
        $isFalse = $b->icmp(
            Builder::INT_EQ,
            $b->load($b->pointerCast(
                $b->structGep($ret, $context->structFieldMap['__value__']['value']),
                $context->getTypeFromString('int8*')
            )),
            $i8->constInt(0, false)
        );
        $retry = $fn->appendBasicBlock('usort_cmp_retry_swapped');
        $b->branchIf($isFalse, $retry, $plain);
        $b->positionAtEnd($retry);
        $ret2 = $invoke($context, $extra, $argBox($y), $argBox($x));
        $b->store($b->sub($i64->constInt(0, false), self::normalize($context, self::zvalGetLong($context, $ret2))), $out);
        $b->branch($done);

        $b->positionAtEnd($done);

        return $b->load($out);
    }

    /** zval_get_long for a returned box: bool, int, float (truncated; 0 if out of range), numeric string, else 0. */
    private static function zvalGetLong(Context $context, Value $box): Value
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $fn = BasicBlockHelper::parentFunction($context);
        $map = $context->structFieldMap['__value__'];
        $kind = $b->and($b->load($b->structGep($box, $map['type'])), $i8->constInt(0x7f, false));
        $out = BasicBlockHelper::entryAlloca($context, $i64);
        $payload8 = $b->load($b->pointerCast($b->structGep($box, $map['value']), $context->getTypeFromString('int8*')));
        $long = $b->call($context->lookupFunction('__value__readLong'), $box);
        $dbl = $b->call($context->lookupFunction('__value__readDouble'), $box);
        $dblFits = $b->and(
            $b->fcmp(Builder::REAL_OGE, $dbl, $f64->constReal(-9223372036854775808.0)),
            $b->fcmp(Builder::REAL_OLT, $dbl, $f64->constReal(9223372036854775808.0))
        );
        $dblLong = $b->select($dblFits, $b->fpToSi($dbl, $i64), $i64->constInt(0, false));
        $isKind = static fn (int $t): Value => $b->icmp(Builder::INT_EQ, $kind, $i8->constInt($t, false));
        $scalar = $b->select(
            $isKind(Variable::TYPE_NATIVE_LONG),
            $long,
            $b->select(
                $isKind(Variable::TYPE_NATIVE_DOUBLE),
                $dblLong,
                $b->select(
                    $isKind(Variable::TYPE_NATIVE_BOOL),
                    $b->zExt($b->icmp(Builder::INT_NE, $payload8, $i8->constInt(0, false)), $i64),
                    $i64->constInt(0, false)
                )
            )
        );
        $b->store($scalar, $out);
        $str = $fn->appendBasicBlock('zgl_string');
        $done = $fn->appendBasicBlock('zgl_done');
        $b->branchIf($isKind(Variable::TYPE_STRING & 0x7f), $str, $done);
        $b->positionAtEnd($str);
        $num = JitValueBox::pointer($context, JitValueBox::alloc($context));
        $status = NumericStringLlvm::call($context, $b->call($context->lookupFunction('__value__readString'), $box), $num);
        $numKind = $b->load($b->structGep($num, $map['type']));
        $numDbl = $b->call($context->lookupFunction('__value__readDouble'), $num);
        $numFits = $b->and(
            $b->fcmp(Builder::REAL_OGE, $numDbl, $f64->constReal(-9223372036854775808.0)),
            $b->fcmp(Builder::REAL_OLT, $numDbl, $f64->constReal(9223372036854775808.0))
        );
        $numLong = $b->select(
            $b->icmp(Builder::INT_EQ, $numKind, $i8->constInt(Variable::TYPE_NATIVE_LONG, false)),
            $b->call($context->lookupFunction('__value__readLong'), $num),
            $b->select($numFits, $b->fpToSi($numDbl, $i64), $i64->constInt(0, false))
        );
        $b->store(
            $b->select(
                $b->icmp(Builder::INT_EQ, $status, $context->getTypeFromString('int32')->constInt(NumericStringLlvm::NON_NUMERIC, false)),
                $i64->constInt(0, false),
                $numLong
            ),
            $out
        );
        $b->branch($done);
        $b->positionAtEnd($done);

        return $b->load($out);
    }

    private static function normalize(Context $context, Value $v): Value
    {
        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        return $b->sub($b->zExt($b->icmp(Builder::INT_SGT, $v, $zero), $i64), $b->zExt($b->icmp(Builder::INT_SLT, $v, $zero), $i64));
    }

    private static function closureObjectPtr(Context $context, Variable $closure): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        if (Variable::TYPE_OBJECT === $closure->type) {
            return $context->builder->pointerCast($context->helper->loadValue($closure), $i8p);
        }

        return $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $closure)
            ),
            $i8p
        );
    }

    private static function deprecationFlag(Context $context): Value
    {
        $name = '__phpc_usort_bool_deprecation_shown';
        $g = $context->module->getNamedGlobal($name);
        if (null === $g) {
            $i8 = $context->getTypeFromString('int8');
            $g = $context->module->addGlobal($i8, $name);
            $g->setInitializer($i8->constInt(0, false));
            $context->llvm->lib->LLVMSetLinkage($g->value, \llvm\llvm::LLVMInternalLinkage);
        }

        return $g;
    }

    private static function emitDeprecation(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $context->builder->pointerCast($context->constantFromString(self::DEPRECATION), $i8p),
            $context->getTypeFromString('size_t')->constInt(\strlen(self::DEPRECATION), false),
            $i32->constInt(\PHPCompiler\VM\ErrorReporter::E_DEPRECATED, false),
            $context->builder->pointerCast($context->constantFromString($context->jitAotEntryScriptPath), $i8p),
            $i32->constInt(max(0, $context->callSiteLine), false)
        );
    }
}
