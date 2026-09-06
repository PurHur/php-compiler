<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MathAbs;
use PHPCompiler\JIT\Builtin\MathRound;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitRoundModeArg;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for round() (int/float, precision, PHP_ROUND_* mode) (#15211).
 *
 * When num/precision/mode are compile-time scalars, evaluate on the host and emit a float
 * constant — NestedJIT RoundJitHelper mis-handles places>0 on cold AOT calls (#27249 / #26800).
 *
 * Runtime num with compile-time places=0 + any PHP_ROUND_* mode use LLVM f64
 * ops ({@see MathRound::invokeHalfUpPlacesZero} / HalfDown / HalfEven / HalfOdd /
 * Ceiling / Floor / TowardZero / AwayFromZero, #36386) — php-src
 * {@code _php_math_round} / {@code php_math_round_mode.h}.
 *
 * Runtime num with compile-time precision≠0 + directed modes (not HALF_UP) scale
 * by {@see RoundJitHelper::pow10abs}, round places=0 via LLVM, then unscale —
 * same algorithm as RoundJitHelper / php-src {@code _php_math_round} (#36386).
 *
 * Runtime num with compile-time precision≠0 + default HALF_UP uses user-TU sprintf+strtod
 * so Zend parity survives thin AOT fmul drift (#35741).
 * Runtime (unknown) places keep the RoundJitHelper NestedJIT bridge.
 */
final class JitRound
{
    public static function round(Context $context, JITVariable ...$args): Value
    {
        // Arity is enforced at round::call via requireArgCountRangeJit (#28229).
        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $precisionArg = isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])
            ? $args[1]
            : null;
        $modeArg = isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])
            ? $args[2]
            : null;
        $knownPlaces = self::compileTimePlaces($precisionArg);
        $knownMode = self::compileTimeRoundMode($context, $modeArg);

        $number = self::coerceDouble($context, $args[0]);
        // places=0 + directed modes: LLVM intrinsics — no NestedJIT helper (#36386).
        if (null !== $knownPlaces && 0 === $knownPlaces && null !== $knownMode) {
            $viaIntrinsic = self::tryInvokePlacesZeroIntrinsic($context, $number, $knownMode);
            if (null !== $viaIntrinsic) {
                return $viaIntrinsic;
            }
        }
        // places≠0 + directed modes: scale → places=0 LLVM → unscale (#36386).
        if (null !== $knownPlaces && 0 !== $knownPlaces && null !== $knownMode
            && StdlibConstants::PHP_ROUND_HALF_UP !== $knownMode) {
            $viaScaled = self::tryLowerRuntimeRoundScaledIntrinsic(
                $context,
                $number,
                $knownPlaces,
                $knownMode
            );
            if (null !== $viaScaled) {
                return $viaScaled;
            }
        }
        if (null !== $knownPlaces && 0 !== $knownPlaces && self::isCompileTimeHalfUp($context, $modeArg)) {
            return self::lowerRuntimeRoundHalfUpSprintf($context, $number, $knownPlaces);
        }

        // Link before lowering remaining args so NestedJIT of RoundJitHelper cannot
        // orphan the first call site's operand IR (#27248 peer strpos/strtok).
        MathRound::ensureLinked($context);
        $precision = null !== $precisionArg
            ? self::lowerPrecisionArg($context, $precisionArg)
            : $context->getTypeFromString('int64')->constInt(0, false);
        $mode = null !== $modeArg
            ? JitRoundModeArg::lower($context, $modeArg, 'round')
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

        $knownPlaces = self::compileTimePlaces($precision);
        $number = self::coerceDouble($context, $num);
        if (null !== $knownPlaces && 0 === $knownPlaces) {
            $viaIntrinsic = self::tryInvokePlacesZeroIntrinsic($context, $number, $mode);
            if (null !== $viaIntrinsic) {
                return $viaIntrinsic;
            }
        }
        if (null !== $knownPlaces && 0 !== $knownPlaces
            && StdlibConstants::PHP_ROUND_HALF_UP !== $mode) {
            $viaScaled = self::tryLowerRuntimeRoundScaledIntrinsic(
                $context,
                $number,
                $knownPlaces,
                $mode
            );
            if (null !== $viaScaled) {
                return $viaScaled;
            }
        }
        if (null !== $knownPlaces && 0 !== $knownPlaces && $mode === StdlibConstants::PHP_ROUND_HALF_UP) {
            return self::lowerRuntimeRoundHalfUpSprintf($context, $number, $knownPlaces);
        }

        MathRound::ensureLinked($context);
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

    private static function compileTimePlaces(?JITVariable $arg): ?int
    {
        if (null === $arg || NamedOptionalCallArgs::isOmittedOptional($arg)) {
            return 0;
        }
        if (null === $arg->compileTimeLong) {
            return null;
        }

        return (int) $arg->compileTimeLong;
    }

    private static function isCompileTimeHalfUp(Context $context, ?JITVariable $modeArg): bool
    {
        $resolved = self::compileTimeRoundMode($context, $modeArg);

        return null !== $resolved && StdlibConstants::PHP_ROUND_HALF_UP === $resolved;
    }

    /**
     * Compile-time PHP_ROUND_* / RoundingMode when resolvable; omitted → HALF_UP.
     */
    private static function compileTimeRoundMode(Context $context, ?JITVariable $modeArg): ?int
    {
        if (null === $modeArg || NamedOptionalCallArgs::isOmittedOptional($modeArg)) {
            return StdlibConstants::PHP_ROUND_HALF_UP;
        }
        $resolved = RoundingModeJit::compileTimeRoundMode($context, $modeArg);
        if (null !== $resolved) {
            return $resolved;
        }
        if (null !== $modeArg->compileTimeLong) {
            return (int) $modeArg->compileTimeLong;
        }

        return null;
    }

    /**
     * places=0 modes with LLVM f64 lowering — no NestedJIT helper (#36386).
     */
    private static function tryInvokePlacesZeroIntrinsic(
        Context $context,
        Value $number,
        int $mode
    ): ?Value {
        if (StdlibConstants::PHP_ROUND_HALF_UP === $mode) {
            return MathRound::invokeHalfUpPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_HALF_DOWN === $mode) {
            return MathRound::invokeHalfDownPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_HALF_EVEN === $mode) {
            return MathRound::invokeHalfEvenPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_HALF_ODD === $mode) {
            return MathRound::invokeHalfOddPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_CEILING === $mode) {
            return MathRound::invokeCeilingPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_FLOOR === $mode) {
            return MathRound::invokeFloorPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_TOWARD_ZERO === $mode) {
            return MathRound::invokeTowardZeroPlacesZero($context, $number);
        }
        if (StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO === $mode) {
            return MathRound::invokeAwayFromZeroPlacesZero($context, $number);
        }

        return null;
    }

    /**
     * round($runtime, literal places≠0, directed mode) — scale by 10^|places|,
     * places=0 LLVM intrinsic, unscale. Matches RoundJitHelper / php-src
     * {@code _php_math_round} (#36386). HALF_UP stays on sprintf (#35741).
     */
    private static function tryLowerRuntimeRoundScaledIntrinsic(
        Context $context,
        Value $number,
        int $places,
        int $mode
    ): ?Value {
        if (!self::isDirectedRoundMode($mode)) {
            return null;
        }

        $placesClamped = $places;
        if ($placesClamped < -9223372036854775807) {
            $placesClamped = -9223372036854775807;
        }
        if ($placesClamped > 308) {
            $placesClamped = 308;
        }
        if ($placesClamped < -308) {
            $placesClamped = -308;
        }
        $absPlaces = $placesClamped < 0 ? -$placesClamped : $placesClamped;
        $exponent = RoundJitHelper::pow10abs($absPlaces);
        $double = $context->getTypeFromString('double');
        $expVal = $double->constReal($exponent);
        $positivePlaces = $placesClamped > 0;
        $scaled = $positivePlaces
            ? $context->builder->fmul($number, $expVal)
            : $context->builder->fdiv($number, $expVal);

        // RoundJitHelper: |scaled| >= 1e16 → return original (precision cliff).
        $absScaled = MathAbs::invokeDouble($context, $scaled);
        $tooBig = $context->builder->fcmp(
            Builder::REAL_OGE,
            $absScaled,
            $double->constReal(1.0e16)
        );
        $rounded = self::tryInvokePlacesZeroIntrinsic($context, $scaled, $mode);
        if (null === $rounded) {
            return null;
        }
        $unscaled = $positivePlaces
            ? $context->builder->fdiv($rounded, $expVal)
            : $context->builder->fmul($rounded, $expVal);

        return $context->builder->select($tooBig, $number, $unscaled);
    }

    /** Modes with places=0 LLVM lowering (everything except HALF_UP / unknown). */
    private static function isDirectedRoundMode(int $mode): bool
    {
        return StdlibConstants::PHP_ROUND_HALF_DOWN === $mode
            || StdlibConstants::PHP_ROUND_HALF_EVEN === $mode
            || StdlibConstants::PHP_ROUND_HALF_ODD === $mode
            || StdlibConstants::PHP_ROUND_CEILING === $mode
            || StdlibConstants::PHP_ROUND_FLOOR === $mode
            || StdlibConstants::PHP_ROUND_TOWARD_ZERO === $mode
            || StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO === $mode;
    }

    /**
     * round($runtime, literal places) with PHP_ROUND_HALF_UP — sprintf in user TU (#35741).
     */
    private static function lowerRuntimeRoundHalfUpSprintf(
        Context $context,
        Value $number,
        int $places
    ): Value {
        $decimals = $places < 0 ? -$places : $places;
        if ($decimals > 15) {
            $decimals = 15;
        }
        $fmtGlobal = $context->constantStringFromString('%.'.$decimals.'f');
        $fmtLoaded = $context->builder->load($fmtGlobal);
        $fmtOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fmtLoaded
        );
        $numVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::KIND_VALUE,
            $number
        );
        $strPtr = JitSprintf::formatWithFmt($context, $fmtOwned, $numVar);
        $charPtr = $context->builder->structGep(
            $strPtr,
            $context->structFieldIndex($strPtr, 'value')
        );

        LibcExtern::ensureStrtodDecl($context);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
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
