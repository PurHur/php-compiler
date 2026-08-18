<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathAbs;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class abs extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'abs', 1);
        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'abs',
            1,
            'num',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (\is_int($num)) {
            if ($num < 0) {
                $abs = -$num;
                if (\is_float($abs)) {
                    $frame->returnVar->float($abs);

                    return;
                }
                $frame->returnVar->int($abs);

                return;
            }
            $frame->returnVar->int($num);

            return;
        }
        // php-src math.c fabs: abs(-0.0) → +0.0 (#23978). `$num < 0.0` is false for -0.0.
        $frame->returnVar->float(AbsJitHelper::absDoubleArgv($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'abs', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $args[0]->type) {
            $v = $context->helper->loadValue($args[0]);

            return MathAbs::invokeDouble($context, $v);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            $constLong = $args[0]->compileTimeLong;
            if (null === $constLong
                && JITVariable::KIND_VALUE === $args[0]->kind
                && null !== $args[0]->value
                && \PHPLLVM\Value::KIND_CONSTANT_INT === $args[0]->value->getKind()
            ) {
                $constLong = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($args[0]->value->value);
            }
            if (\PHP_INT_MIN === $constLong) {
                return $context->getTypeFromString('double')->constReal(-(float) $constLong);
            }
            $v = JitLongArg::lower($context, $args[0], 'abs() argument #1');

            return MathAbs::invokeLong($context, $v);
        }
        // Boxed long from overflow-checked +/* (#31964) must stay int unless ZEND_LONG_MIN
        // (php-src math.c PHP_FUNCTION(abs)). lowerToDouble made abs(PHP_INT_MIN+1) a float (#32309).
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            return self::callBoxed($context, $args[0]);
        }
        $asFloat = JitMathNumberArg::lowerToDouble($context, $args[0], 'abs', 1, 'num');

        return MathAbs::invokeDouble($context, $asFloat);
    }

    /**
     * php-src ext/standard/math.c PHP_FUNCTION(abs): IS_LONG (not ZEND_LONG_MIN) → RETURN_LONG.
     */
    private static function callBoxed(Context $context, JITVariable $arg): Value
    {
        MathAbs::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'abs_boxed_cont');
        $inPtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $outSlot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($inPtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');

        $longBB = BasicBlockHelper::append($context, 'abs_boxed_long');
        $doubleBB = BasicBlockHelper::append($context, 'abs_boxed_double');
        $otherBB = BasicBlockHelper::append($context, 'abs_boxed_other');
        $doneBB = BasicBlockHelper::append($context, 'abs_boxed_done');
        $checkDouble = BasicBlockHelper::append($context, 'abs_boxed_chk_dbl');

        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBB, $checkDouble);

        $context->builder->positionAtEnd($checkDouble);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBB, $otherBB);

        $context->builder->positionAtEnd($longBB);
        $lv = $context->builder->call($context->lookupFunction('__value__readLong'), $inPtr);
        $isMin = $context->builder->icmp(
            Builder::INT_EQ,
            $lv,
            $i64->constInt(\PHP_INT_MIN, true)
        );
        $minBB = BasicBlockHelper::append($context, 'abs_boxed_int_min');
        $okBB = BasicBlockHelper::append($context, 'abs_boxed_int_ok');
        $context->builder->branchIf($isMin, $minBB, $okBB);

        $context->builder->positionAtEnd($minBB);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $outPtr,
            $f64->constReal(-(float) \PHP_INT_MIN)
        );
        $context->builder->branch($doneBB);

        $context->builder->positionAtEnd($okBB);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            MathAbs::invokeLong($context, $lv)
        );
        $context->builder->branch($doneBB);

        $context->builder->positionAtEnd($doubleBB);
        $dv = $context->builder->call($context->lookupFunction('__value__readDouble'), $inPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $outPtr,
            MathAbs::invokeDouble($context, $dv)
        );
        $context->builder->branch($doneBB);

        $context->builder->positionAtEnd($otherBB);
        $asFloat = JitMathNumberArg::lowerToDouble($context, $arg, 'abs', 1, 'num');
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $outPtr,
            MathAbs::invokeDouble($context, $asFloat)
        );
        $context->builder->branch($doneBB);

        $context->builder->positionAtEnd($doneBB);
        JitValueBox::publishAfterWrite($context, $outPtr);

        return $outPtr;
    }
}
