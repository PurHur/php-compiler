<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MathRound;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitRoundModeArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for round() (int/float, precision, PHP_ROUND_* mode) via RoundJitHelper (#15211).
 *
 * When num/precision/mode are compile-time scalars, evaluate on the host and emit a float
 * constant — NestedJIT RoundJitHelper mis-handles places>0 on cold AOT calls (#27249 / #26800).
 */
final class JitRound
{
    public static function round(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('round() requires one to three arguments');
        }

        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Link before lowering args so NestedJIT of RoundJitHelper cannot orphan the
        // first call site's operand IR (#27248 peer strpos/strtok).
        MathRound::ensureLinked($context);

        $number = self::coerceDouble($context, $args[0]);
        $precision = isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])
            ? self::lowerPrecisionArg($context, $args[1])
            : $context->getTypeFromString('int64')->constInt(0, false);
        $mode = isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])
            ? JitRoundModeArg::lower($context, $args[2], 'round')
            : $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);

        return MathRound::invoke($context, $number, $precision, $mode);
    }

    public static function roundWithModeInt(
        Context $context,
        JITVariable $num,
        ?JITVariable $precision,
        int $mode
    ): Value {
        // Mode is a known int — fold when num (+ optional precision) are compile-time.
        if (null === $precision) {
            $folded = self::tryFoldCompileTime($context, [
                $num,
                JITVariable::fromConstantInt($context, 0),
                JITVariable::fromConstantInt($context, $mode),
            ]);
        } else {
            $folded = self::tryFoldCompileTime($context, [
                $num,
                $precision,
                JITVariable::fromConstantInt($context, $mode),
            ]);
        }
        if (null !== $folded) {
            return $folded;
        }

        MathRound::ensureLinked($context);

        $number = self::coerceDouble($context, $num);
        $prec = null !== $precision
            ? self::lowerPrecisionArg($context, $precision)
            : $context->getTypeFromString('int64')->constInt(0, false);
        $modeVal = $context->getTypeFromString('int64')->constInt($mode, false);

        return MathRound::invoke($context, $number, $prec, $modeVal);
    }

    /**
     * Host-evaluate round() when all operands are compile-time scalars (#27249).
     *
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $num = self::compileTimeNumber($args[0] ?? null);
        if (null === $num) {
            return null;
        }
        $places = 0;
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            if (null === $args[1]->compileTimeLong) {
                return null;
            }
            $places = (int) $args[1]->compileTimeLong;
        }
        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            // Resolve RoundingMode::… at fold time so constant modes never NestedJIT
            // RoundJitHelper (avoids AOT module-verify / writeString ABI traps — #26939 / #26800).
            $resolvedMode = RoundingModeJit::compileTimeRoundMode($context, $args[2]);
            if (null === $resolvedMode && null !== $args[2]->compileTimeLong) {
                $resolvedMode = (int) $args[2]->compileTimeLong;
            }
            if (null === $resolvedMode) {
                return null;
            }
            $mode = $resolvedMode;
        }

        $result = RoundJitHelper::roundArgv((float) $num, $places, $mode);

        return $context->getTypeFromString('double')->constReal($result);
    }

    private static function compileTimeNumber(?JITVariable $arg): ?float
    {
        if (null === $arg) {
            return null;
        }
        if (null !== $arg->compileTimeFloat) {
            return (float) $arg->compileTimeFloat;
        }
        if (null !== $arg->compileTimeLong) {
            return (float) $arg->compileTimeLong;
        }

        return null;
    }

    private static function lowerPrecisionArg(Context $context, JITVariable $arg): Value
    {
        JitInternalStrictArg::requireInt($context, $arg, 'round', 'precision', 2);

        return JitIntdiv::lowerIntBuiltinArg($context, $arg, 'round', 2, 'precision');
    }

    private static function coerceDouble(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $value = $context->helper->loadValue($arg);
            $f64 = $context->getTypeFromString('double');

            return $context->builder->sitofp($value, $f64);
        }

        return JitMathNumberArg::lowerToDouble($context, $arg, 'round', 1, 'num');
    }
}
