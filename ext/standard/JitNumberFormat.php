<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFormat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float/numeric string, arity 1–4).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_DOUBLE / Z_PARAM_LONG / Z_PARAM_STR
 */
final class JitNumberFormat
{
    private const MAX_ARGS = 4;

    /**
     * @param JITVariable ...$args
     */
    public static function assertArgCount(Context $context, JITVariable ...$args): void
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc <= self::MAX_ARGS) {
            return;
        }

        throw new \ArgumentCountError(\sprintf(
            'number_format() expects at most %d arguments, %d given',
            self::MAX_ARGS,
            $argc
        ));
    }

    public static function format(Context $context, JITVariable ...$args): Value
    {
        // User-standalone init skips StringFormat::ensureLinked (#13571) —
        // link __compiler_number_format on first call-site lowering (#15642, #18525).
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            StringFormat::implementIfDeclared($context, true);
        }

        $argc = \count($args);

        // strict_types only — PROFILE=8.4 still soft-nulls like Zend Z_PARAM_DOUBLE (#21429).
        if (self::rejectNullNum($context, $args[0])) {
            // Catchable TypeError terminated the block — dummy return for IR (#29976).
            return $context->getTypeFromString('__string__*')->constNull();
        }

        // TypeError cites int|float (stub); weak null DEP stays "float" via JitFdiv (#29976).
        $number = JitFdiv::lowerSingleOperand(
            $context,
            $args[0],
            1,
            'num',
            'number_format',
            'int|float',
            false
        );
        $i64 = $context->getTypeFromString('int64');
        // Z_PARAM_LONG $decimals — strict_types → TypeError on null; else soft-null (#29764).
        $decimals = ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]))
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'number_format', 2, 'decimals')
            : $i64->constInt(0, false);
        $mode = $i64->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);

        // php-src math.c: round then MAX(0,dec) (#27899). Pre-round here so NestedJIT
        // SprintfJitHelper never sees negative $decimals (hangs / wrong under thin AOT).
        if (CompilerVersion::supportsNumberFormatNegativeDecimals()) {
            $number = self::preRoundNegativeDecimals($context, $number, $args, $argc, $decimals);
            $negDec = $context->builder->icmp(Builder::INT_SLT, $decimals, $i64->constInt(0, false));
            $decimals = $context->builder->select($negDec, $i64->constInt(0, false), $decimals);
        } else {
            // Zend 8.2 / reference: ignore negative like 0.
            $negDec = $context->builder->icmp(Builder::INT_SLT, $decimals, $i64->constInt(0, false));
            $decimals = $context->builder->select($negDec, $i64->constInt(0, false), $decimals);
        }

        $decSep = ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2]))
            ? JitStringBuiltinArg::lower($context, $args[2], 'number_format', 2, 'decimal_separator', '?string')
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = ($argc >= 4 && !NamedOptionalCallArgs::isOmittedOptional($args[3]))
            ? JitStringBuiltinArg::lower($context, $args[3], 'number_format', 3, 'thousands_separator', '?string')
            : $context->builder->load($context->constantStringFromString(','));

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep,
            $mode
        );
    }

    /**
     * Host-fold or IR-scale round for negative $decimals (php-src _php_math_round).
     *
     * Avoids MathRound/NestedJIT RoundJitHelper from number_format thin AOT (#27899 hang).
     *
     * @param JITVariable ...$args
     */
    private static function preRoundNegativeDecimals(
        Context $context,
        Value $number,
        array $args,
        int $argc,
        Value $decimals
    ): Value {
        $double = $context->getTypeFromString('double');
        $placesConst = null;
        if ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1])
            && null !== $args[1]->compileTimeLong) {
            $placesConst = (int) $args[1]->compileTimeLong;
        }
        if (null === $placesConst || $placesConst >= 0) {
            // Runtime / non-negative places: only negative needs pre-round; skip.
            if (null === $placesConst) {
                // Runtime places: branch — if decimals < 0, scale-round in IR.
                return self::emitRuntimeNegativePlacesRound($context, $number, $decimals);
            }

            return $number;
        }

        // Constant negative places — prefer host fold when $num is also constant.
        $numConst = self::compileTimeFloat($args[0]);
        if (null !== $numConst) {
            $rounded = RoundMath::mathRound($numConst, $placesConst, StdlibConstants::PHP_ROUND_HALF_UP);

            return $double->constReal($rounded);
        }

        return self::emitConstNegativePlacesRound($context, $number, $placesConst);
    }

    private static function compileTimeFloat(JITVariable $arg): ?float
    {
        if (null !== $arg->compileTimeFloat) {
            return (float) $arg->compileTimeFloat;
        }
        if (null !== $arg->compileTimeLong) {
            return (float) $arg->compileTimeLong;
        }

        return null;
    }

    private static function emitConstNegativePlacesRound(Context $context, Value $number, int $places): Value
    {
        $double = $context->getTypeFromString('double');
        $ap = -$places;
        $exp = 1.0;
        for ($i = 0; $i < $ap; ++$i) {
            $exp *= 10.0;
        }
        $expVal = $double->constReal($exp);
        $scaled = $context->builder->fdiv($number, $expVal);
        // half-up toward +inf for positive; for signed use same as (int)(x+0.5) on abs later —
        // match RoundMath HALF_UP via fptosi(fadd(scaled, copysign(0.5))).
        $half = $double->constReal(0.5);
        $negHalf = $double->constReal(-0.5);
        $zero = $double->constReal(0.0);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $scaled, $zero);
        $adj = $context->builder->select($isNeg, $negHalf, $half);
        $biased = $context->builder->fadd($scaled, $adj);
        $asInt = $context->builder->fpToSi($biased, $context->getTypeFromString('int64'));
        $asFloat = $context->builder->siToFp($asInt, $double);

        return $context->builder->fmul($asFloat, $expVal);
    }

    private static function emitRuntimeNegativePlacesRound(Context $context, Value $number, Value $decimals): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $decimals, $i64->constInt(0, false));
        $okBlock = BasicBlockHelper::append($context, 'number_format_negdec_ok');
        $roundBlock = BasicBlockHelper::append($context, 'number_format_negdec_round');
        $mergeBlock = BasicBlockHelper::append($context, 'number_format_negdec_merge');
        $context->builder->branchIf($isNeg, $roundBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($roundBlock);
        // exp = 10^(-decimals); limited to |places|<=15 via host table for NestedJIT-safe IR.
        $ap = $context->builder->sub($i64->constInt(0, false), $decimals);
        $exp = self::emitPow10Select($context, $ap);
        $scaled = $context->builder->fdiv($number, $exp);
        $half = $double->constReal(0.5);
        $negHalf = $double->constReal(-0.5);
        $zero = $double->constReal(0.0);
        $scaledNeg = $context->builder->fcmp(Builder::REAL_OLT, $scaled, $zero);
        $adj = $context->builder->select($scaledNeg, $negHalf, $half);
        $biased = $context->builder->fadd($scaled, $adj);
        $asInt = $context->builder->fpToSi($biased, $i64);
        $asFloat = $context->builder->siToFp($asInt, $double);
        $rounded = $context->builder->fmul($asFloat, $exp);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($double);
        $phi->addIncoming($number, $okBlock);
        $phi->addIncoming($rounded, $roundBlock);

        return $phi;
    }

    private static function emitPow10Select(Context $context, Value $ap): Value
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        // Clamp ap to [0,15] then ladder — mirrors RoundJitHelper::pow10abs NestedJIT style.
        $zero = $i64->constInt(0, false);
        $max = $i64->constInt(15, false);
        $lt0 = $context->builder->icmp(Builder::INT_SLT, $ap, $zero);
        $ap = $context->builder->select($lt0, $zero, $ap);
        $gt = $context->builder->icmp(Builder::INT_SGT, $ap, $max);
        $ap = $context->builder->select($gt, $max, $ap);

        $pow = [
            1.0, 10.0, 100.0, 1000.0, 10000.0, 100000.0, 1000000.0, 10000000.0,
            100000000.0, 1000000000.0, 10000000000.0, 100000000000.0, 1000000000000.0,
            10000000000000.0, 100000000000000.0, 1000000000000000.0,
        ];
        $result = $double->constReal(1.0);
        for ($i = 15; $i >= 0; --$i) {
            $eq = $context->builder->icmp(Builder::INT_EQ, $ap, $i64->constInt($i, false));
            $result = $context->builder->select($eq, $double->constReal($pow[$i]), $result);
        }

        return $result;
    }

    /**
     * Reject null $num under declare(strict_types=1).
     *
     * @return bool true when compile-time null emitted a catchable/fatal TypeError —
     *              caller must return a dummy value and stop IR emission
     */
    private static function rejectNullNum(Context $context, JITVariable $arg): bool
    {
        // Only declare(strict_types=1) rejects null; forward profile soft-nulls (#21429).
        if (!$context->callerStrictTypes) {
            return false;
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitNullNumTypeErrorAndAbort($context);

            return true;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return false;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'number_format_num_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'number_format_num_null_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::emitNullNumTypeErrorAndAbort($context);
        $context->builder->positionAtEnd($okBlock);

        return false;
    }

    private static function emitNullNumTypeErrorAndAbort(Context $context): void
    {
        // Catchable under try/catch (AOT standalone uses pending-handler path; #29976).
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            'number_format(): Argument #1 ($num) must be of type int|float, null given'
        );
    }

}
