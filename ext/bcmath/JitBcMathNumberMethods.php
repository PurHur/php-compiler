<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT BcMath\Number::{add,mul,compare} (#26803).
 *
 * php-src: ext/bcmath/bcmath.c — PHP_METHOD(BcMath_Number, add|mul|compare)
 * VM SSOT: {@see NumberAdd}, {@see NumberMul}, {@see NumberCompare}
 *
 * User-script AOT previously lowered unbound methods to silent null (#579).
 */
final class JitBcMathNumberMethods
{
    /** @var array{value: string, scale: int}|null */
    private static ?array $lastCompileTimeResult = null;

    /** @return array{value: string, scale: int}|null */
    public static function takeLastCompileTimeResult(): ?array
    {
        $ct = self::$lastCompileTimeResult;
        self::$lastCompileTimeResult = null;

        return $ct;
    }

    public static function call(Context $context, string $method, Variable ...$args): Value
    {
        self::$lastCompileTimeResult = null;
        $methodLc = strtolower($method);
        if ([] === $args) {
            throw new \LogicException('BcMath\\Number::'.$methodLc.'() requires $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'BcMath\\Number::'.$methodLc.'() expects at least 1 argument, 0 given'
            );
        }

        return match ($methodLc) {
            'add' => self::binaryNumber($context, OpCode::TYPE_PLUS, 'add', $args),
            'mul' => self::binaryNumber($context, OpCode::TYPE_MUL, 'mul', $args),
            'compare' => self::compare($context, $args),
            default => throw new \LogicException(
                'BcMath\\Number JIT lowering is not implemented for '.$methodLc.'()'
            ),
        };
    }

    /** @param array<int, Variable> $args */
    private static function binaryNumber(Context $context, int $opType, string $method, array $args): Value
    {
        $folded = self::tryFoldBinary($context, $opType, $args);
        if (null !== $folded) {
            return $folded->value;
        }

        self::ensureLinked($context);

        $leftStr = JitBcMathNumberInit::loadValueString($context, $args[0]);
        $leftScale = JitBcMathNumberInit::loadScaleLong($context, $args[0]);
        [$rightStr, $rightScale] = self::lowerOperand($context, $args[1], $method);
        $scale = self::effectiveScaleLlvm($context, $opType, $leftScale, $rightScale, $args[2] ?? null, $method);

        $fn = OpCode::TYPE_PLUS === $opType ? '__compiler_bcadd' : '__compiler_bcmul';
        $i64 = $context->getTypeFromString('int64');
        $left = JitStringBuiltinArg::lower(
            $context,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $leftStr),
            'BcMath\\Number::'.$method,
            0,
            'num'
        );
        $outVal = $context->builder->call(
            $context->lookupFunction($fn),
            $left,
            $rightStr,
            $scale,
            $i64->constInt(1, true),
            $i64->constInt(0, true),
            $i64->constInt(-1, true)
        );
        $outVal = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $outVal
        );
        $boxed = JitBcMathNumberInit::boxNewNumber($context, $outVal, $scale);

        return $boxed->value;
    }

    /** @param array<int, Variable> $args */
    private static function compare(Context $context, array $args): Value
    {
        $folded = self::tryFoldCompare($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        self::ensureLinked($context);

        $leftStrVal = JitBcMathNumberInit::loadValueString($context, $args[0]);
        $leftScale = JitBcMathNumberInit::loadScaleLong($context, $args[0]);
        [$rightStr, $rightScale] = self::lowerOperand($context, $args[1], 'compare');
        $i64 = $context->getTypeFromString('int64');
        if (isset($args[2])) {
            $scale = self::lowerOptionalScale($context, $args[2], 'compare');
        } else {
            // php-src bcmath_number_compare — max operand frac length when scale omitted.
            $scale = $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $leftScale, $rightScale),
                $leftScale,
                $rightScale
            );
        }
        $left = JitStringBuiltinArg::lower(
            $context,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $leftStrVal),
            'BcMath\\Number::compare',
            0,
            'num'
        );
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_bccomp'),
            $left,
            $rightStr,
            $scale,
            $i64->constInt(1, true)
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $result);

        return $slot;
    }

    /** @param array<int, Variable> $args */
    private static function tryFoldBinary(Context $context, int $opType, array $args): ?Variable
    {
        $leftCt = self::compileTimeOperand($args[0]);
        $rightCt = self::compileTimeOperand($args[1]);
        if (null === $leftCt || null === $rightCt) {
            return null;
        }
        $scaleOverride = null;
        if (isset($args[2])) {
            $scaleOverride = self::compileTimeScale($args[2]);
            if (null === $scaleOverride) {
                return null;
            }
        }
        if (null !== $scaleOverride) {
            $outValue = OpCode::TYPE_PLUS === $opType
                ? VmBcmath::add($leftCt['value'], $rightCt['value'], $scaleOverride)
                : VmBcmath::mul($leftCt['value'], $rightCt['value'], $scaleOverride);
            $outScale = $scaleOverride;
        } else {
            [$outValue, $outScale] = VmBcMathNumber::computeBinary(
                $opType,
                $leftCt['value'],
                $leftCt['scale'],
                $rightCt['value'],
                $rightCt['scale'],
                false
            );
        }
        $valueStr = $context->builder->load($context->constantStringFromString($outValue));
        $scaleLong = $context->getTypeFromString('int64')->constInt($outScale, true);
        self::$lastCompileTimeResult = ['value' => $outValue, 'scale' => $outScale];

        return JitBcMathNumberInit::boxNewNumber(
            $context,
            $valueStr,
            $scaleLong,
            self::$lastCompileTimeResult
        );
    }

    /** @param array<int, Variable> $args */
    private static function tryFoldCompare(Context $context, array $args): ?Value
    {
        $leftCt = self::compileTimeOperand($args[0]);
        $rightCt = self::compileTimeOperand($args[1]);
        if (null === $leftCt || null === $rightCt) {
            return null;
        }
        $scale = null;
        if (isset($args[2])) {
            $scale = self::compileTimeScale($args[2]);
            if (null === $scale) {
                return null;
            }
        }
        $cmp = VmBcmath::compNumber($leftCt['value'], $rightCt['value'], $scale);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->getTypeFromString('int64')->constInt($cmp, true)
        );

        return $slot;
    }

    /** @return array{value: string, scale: int}|null */
    private static function compileTimeOperand(Variable $arg): ?array
    {
        $ct = $arg->compileTimeBcmathNumber ?? null;
        if (null !== $ct) {
            return $ct;
        }
        $long = self::compileTimeLong($arg);
        if (null !== $long) {
            $lit = (string) $long;

            return [
                'value' => VmBcmath::canonicalNumberString($lit),
                'scale' => 0,
            ];
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null === $lit) {
            return null;
        }

        return [
            'value' => VmBcmath::canonicalNumberString($lit),
            'scale' => VmBcmath::decimalScale($lit),
        ];
    }

    private static function compileTimeLong(Variable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            $const = $arg->value;
            if ($const instanceof Value && method_exists($const, 'isConstant') && $const->isConstant()) {
                if (method_exists($const, 'constInt')) {
                    return (int) $const->constInt();
                }
                if (method_exists($const, 'getConstantValue')) {
                    return (int) $const->getConstantValue();
                }
            }
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type && Variable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && method_exists($const, 'isConstant') && $const->isConstant()
                && method_exists($const, 'constDouble')) {
                return (int) $const->constDouble();
            }
        }
        $numeric = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $numeric && is_numeric($numeric) && !str_contains($numeric, '.')) {
            return (int) $numeric;
        }

        return null;
    }

    private static function compileTimeScale(Variable $arg): ?int
    {
        return self::compileTimeLong($arg);
    }

    /**
     * @return array{0: Value, 1: Value} right digit string + scale long
     */
    private static function lowerOperand(Context $context, Variable $arg, string $method): array
    {
        $ct = self::compileTimeOperand($arg);
        if (null !== $ct) {
            $str = $context->builder->load($context->constantStringFromString($ct['value']));
            $scale = $context->getTypeFromString('int64')->constInt($ct['scale'], true);
            $lowered = JitStringBuiltinArg::lower(
                $context,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str),
                'BcMath\\Number::'.$method,
                0,
                'num'
            );

            return [$lowered, $scale];
        }

        if (Variable::TYPE_OBJECT === $arg->type) {
            $str = JitBcMathNumberInit::loadValueString($context, $arg);
            $scale = JitBcMathNumberInit::loadScaleLong($context, $arg);
            $lowered = JitStringBuiltinArg::lower(
                $context,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str),
                'BcMath\\Number::'.$method,
                0,
                'num'
            );

            return [$lowered, $scale];
        }

        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            // Int → decimal string at runtime (avoid JitStringBuiltinArg on bare longs).
            $long = $context->helper->loadValue($arg);
            $str = \PHPCompiler\JIT\JitNativeString::formatIndexKey($context, $long);
            $scale = $context->getTypeFromString('int64')->constInt(0, true);

            return [$str, $scale];
        }

        $lowered = JitStringBuiltinArg::lower($context, $arg, 'BcMath\\Number::'.$method, 0, 'num');
        $scale = $context->getTypeFromString('int64')->constInt(0, true);

        return [$lowered, $scale];
    }

    private static function lowerOptionalScale(Context $context, Variable $arg, string $method): Value
    {
        $ct = self::compileTimeScale($arg);
        if (null !== $ct) {
            return $context->getTypeFromString('int64')->constInt($ct, true);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        throw new \LogicException(
            'BcMath\\Number::'.$method.'() scale must be a compile-time int in this compiler build (#26803)'
        );
    }

    private static function effectiveScaleLlvm(
        Context $context,
        int $opType,
        Value $leftScale,
        Value $rightScale,
        ?Variable $scaleArg,
        string $method
    ): Value {
        if (null !== $scaleArg) {
            return self::lowerOptionalScale($context, $scaleArg, $method);
        }
        if (OpCode::TYPE_MUL === $opType) {
            return $context->builder->add($leftScale, $rightScale);
        }

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $leftScale, $rightScale),
            $leftScale,
            $rightScale
        );
    }

    private static function ensureLinked(Context $context): void
    {
        $resume = BasicBlockHelper::tryGetInsertBlock($context);
        Bcmath::ensureLinked($context);
        if (null !== $resume) {
            BasicBlockHelper::restoreInsertBlock($context, $resume);
        }
    }
}
