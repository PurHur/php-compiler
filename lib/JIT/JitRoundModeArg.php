<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\JitRoundModeResolve;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Value;

/**
 * Lower round() mode parameter (int legacy + RoundingMode enum, #5934).
 */
final class JitRoundModeArg
{
    public static function lower(Context $context, Variable $arg, string $fn, string $paramName = 'mode', int $argNum = 3): Value
    {
        $compileTime = RoundingModeJit::compileTimeRoundMode($context, $arg)
            ?? JitRoundModeResolve::tryResolveMode($context, $arg, $context->jitEnclosingBlock);
        if (null !== $compileTime) {
            if (CompilerVersion::supportsRoundingModeEnum() && !VmRoundMode::isValidLegacyIntMode($compileTime)) {
                self::emitInvalidModeAndAbort($context, $fn, $argNum, $paramName);

                return $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);
            }

            return $context->getTypeFromString('int64')->constInt($compileTime, false);
        }

        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            $lowered = $context->helper->loadValue($arg);
            if (CompilerVersion::supportsRoundingModeEnum()) {
                return self::lowerRuntimeModeWithValidation($context, $lowered, $fn, $argNum, $paramName);
            }

            return $lowered;
        }

        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, $fn, 'object', $paramName, $argNum);

            return $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);
        }

        // Soft-null RoundingMode|int — DEP then ValueError for coerced mode 0 (#29384).
        if (Variable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $fn, 'null', $paramName, $argNum);

                return $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);
            }
            JitIntdiv::emitNullIntDeprecation($context, $fn, $argNum, $paramName, 'RoundingMode|int');
            if (CompilerVersion::supportsRoundingModeEnum()) {
                self::emitInvalidModeAndAbort($context, $fn, $argNum, $paramName);
            }

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $lowered = JitIntdiv::lowerIntBuiltinArg($context, $arg, $fn, $argNum, $paramName);
        if (CompilerVersion::supportsRoundingModeEnum()) {
            return self::lowerRuntimeModeWithValidation($context, $lowered, $fn, $argNum, $paramName);
        }

        return $lowered;
    }

    private static function lowerRuntimeModeWithValidation(
        Context $context,
        Value $mode,
        string $fn,
        int $argNum,
        string $paramName
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $valid = $i64->constInt(0, false);
        foreach ([
            StdlibConstants::PHP_ROUND_HALF_UP,
            StdlibConstants::PHP_ROUND_HALF_DOWN,
            StdlibConstants::PHP_ROUND_HALF_EVEN,
            StdlibConstants::PHP_ROUND_HALF_ODD,
            StdlibConstants::PHP_ROUND_CEILING,
            StdlibConstants::PHP_ROUND_FLOOR,
            StdlibConstants::PHP_ROUND_TOWARD_ZERO,
            StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO,
        ] as $allowed) {
            $eq = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $mode,
                $i64->constInt($allowed, false)
            );
            $valid = $context->builder->or($valid, $eq);
        }
        $okBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'round_mode_ok');
        $badBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'round_mode_bad');
        $context->builder->branchIf($valid, $okBb, $badBb);
        $context->builder->positionAtEnd($badBb);
        self::emitInvalidModeAndAbort($context, $fn, $argNum, $paramName);
        $context->builder->positionAtEnd($okBb);

        return $mode;
    }

    private static function emitInvalidModeAndAbort(
        Context $context,
        string $fn,
        int $argNum,
        string $paramName
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            VmRoundMode::invalidModeMessage($fn, $argNum, $paramName)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $fn,
        string $given,
        string $paramName = 'mode',
        int $argNum = 3
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $argNum,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
