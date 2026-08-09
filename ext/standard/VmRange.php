<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * range() VM helpers — int/float/char paths (php-src ext/standard/array.c php_range; #4258).
 */
final class VmRange
{
    public static function build(Frame $frame, Variable $startVar, Variable $endVar, ?Variable $stepVar): HashTable
    {
        $startVar = $startVar->resolveIndirect();
        $endVar = $endVar->resolveIndirect();
        $stepVar = null !== $stepVar ? $stepVar->resolveIndirect() : null;

        // php-src PHP_FUNCTION(range) / php_range (ext/standard/array.c): if step (or either
        // bound) is IS_DOUBLE, take the double path even when both bounds are single letters —
        // char path only when step is not double (#24399).
        $useFloat = self::endpointPrefersFloat($startVar)
            || self::endpointPrefersFloat($endVar)
            || (null !== $stepVar && self::endpointPrefersFloat($stepVar));

        if ($useFloat) {
            $start = self::parseFloatEndpoint($startVar, $frame, 1, 'start');
            $end = self::parseFloatEndpoint($endVar, $frame, 2, 'end');
            $step = self::resolveFloatStep($stepVar, $start, $end, $frame);

            return self::buildFloatRange($start, $end, $step);
        }

        $startChar = self::charRangeLetter($startVar);
        $endChar = self::charRangeLetter($endVar);
        if (null !== $startChar && null !== $endChar) {
            // php-src php_range_process_input — multi-byte non-numeric strings warn then use byte 0 (#29203).
            self::warnMultiByteCharEndpoint($frame, $startVar, 1, 'start');
            self::warnMultiByteCharEndpoint($frame, $endVar, 2, 'end');
            $step = self::resolveCharStep($stepVar, $startChar, $endChar, $frame);

            return self::buildCharRange($startChar, $endChar, $step);
        }

        $start = self::parseIntEndpoint($startVar, $frame, 1, 'start');
        $end = self::parseIntEndpoint($endVar, $frame, 2, 'end');
        $step = self::resolveIntStep($stepVar, $start, $end, $frame);

        return self::buildIntRange($start, $end, $step);
    }

    /**
     * php-src php_range_process_input / PHP_FUNCTION(range) char path (#28830):
     * non-numeric strings use byte 0 (multi-byte endpoints discard subsequent bytes).
     * Empty and numeric strings stay on the int/float paths.
     */
    private static function charRangeLetter(Variable $var): ?string
    {
        if (Variable::TYPE_STRING !== $var->type) {
            return null;
        }
        $s = $var->toString();
        if ('' === $s || is_numeric($s)) {
            return null;
        }

        return $s[0];
    }

    /**
     * php-src php_range_process_input — E_WARNING when Z_STRLEN != 1 under PHP 8.3+ (#29203).
     */
    private static function warnMultiByteCharEndpoint(
        Frame $frame,
        Variable $var,
        int $argIndex,
        string $paramName
    ): void {
        if (!CompilerVersion::supportsRangeSingleByteStringWarning()) {
            return;
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            return;
        }
        $s = $var->toString();
        if (\strlen($s) <= 1) {
            return;
        }
        $message = \sprintf(
            'range(): Argument #%d ($%s) must be a single byte, subsequent bytes are ignored',
            $argIndex,
            $paramName
        );
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame,
                $frame->callSiteLine
            );

            return;
        }
        if (\function_exists('compiler_language_warning')) {
            compiler_language_warning($message);
        }
    }

    private static function endpointPrefersFloat(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $var->type) {
            return true;
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();
            if ('' === $s || !is_numeric($s)) {
                return false;
            }

            return str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E');
        }

        return false;
    }

    private static function parseIntEndpoint(Variable $var, Frame $frame, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return VmMath::floatToZendLong($var->toFloat());
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $var->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();
            if ('' === $s || !is_numeric($s)) {
                return (int) $s;
            }
            if (str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E')) {
                return VmMath::floatToZendLong((float) $s);
            }

            return (int) $s;
        }
        $enumInt = EnumCaseSupport::tryCastToInt($var, $frame->vmContext, $frame);
        if (null !== $enumInt) {
            return $enumInt;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            if (CompilerVersion::supportsRangeStrictEndpointTypes()) {
                throw self::endpointTypeError($argIndex, $paramName, $var);
            }

            return 0;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            if (CompilerVersion::supportsRangeStrictEndpointTypes()) {
                throw self::endpointTypeError($argIndex, $paramName, $var);
            }
            if (ResourceSupport::isResourceObject($var->toObject())) {
                $handle = ResourceSupport::resolveHandle($var);

                return null !== $handle ? $handle : 0;
            }
            $coerced = new Variable();
            VmScalarType::legacyPlainObjectScalarCast($coerced, $var, $frame, 'int');

            return $coerced->toInt();
        }

        throw self::endpointTypeError($argIndex, $paramName, $var);
    }

    private static function parseFloatEndpoint(Variable $var, Frame $frame, int $argIndex, string $paramName): float
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return (float) $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1.0 : 0.0;
        }
        if (Variable::TYPE_NULL === $var->type) {
            return 0.0;
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();
            if ('' === $s || !is_numeric($s)) {
                return (float) (int) $s;
            }

            return (float) $s;
        }
        $enumFloat = EnumCaseSupport::tryCastToFloat($var, $frame->vmContext, $frame);
        if (null !== $enumFloat) {
            return $enumFloat;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            if (CompilerVersion::supportsRangeStrictEndpointTypes()) {
                throw self::endpointTypeError($argIndex, $paramName, $var);
            }

            return 0.0;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            if (CompilerVersion::supportsRangeStrictEndpointTypes()) {
                throw self::endpointTypeError($argIndex, $paramName, $var);
            }
            if (ResourceSupport::isResourceObject($var->toObject())) {
                $handle = ResourceSupport::resolveHandle($var);

                return (float) (null !== $handle ? $handle : 0);
            }
            $coerced = new Variable();
            VmScalarType::legacyPlainObjectScalarCast($coerced, $var, $frame, 'float');

            return $coerced->toFloat();
        }

        throw self::endpointTypeError($argIndex, $paramName, $var);
    }

    private static function endpointTypeError(int $argIndex, string $paramName, Variable $var): \TypeError
    {
        return new \TypeError(
            sprintf(
                'range(): Argument #%d ($%s) must be of type int|float|string, %s given',
                $argIndex,
                $paramName,
                VmStreamArg::debugTypeName($var)
            )
        );
    }

    /**
     * Z_PARAM_NUMBER $step null: strict_types → TypeError; else DEP then zero-step ValueError (#29352).
     *
     * @throws \TypeError|\ValueError
     */
    private static function rejectOrCoerceNullStep(?Frame $frame): never
    {
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            throw new \TypeError(
                'range(): Argument #3 ($step) must be of type int|float, null given'
            );
        }
        VmNullNumberParamDeprecation::emit($frame, 'range', 3, 'step', 'int|float');
        throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
    }

    private static function resolveIntStep(
        ?Variable $stepVar,
        int $start,
        int $end,
        ?Frame $frame = null
    ): int {
        if (null === $stepVar) {
            return $start > $end ? -1 : 1;
        }
        if (Variable::TYPE_NULL === $stepVar->type) {
            self::rejectOrCoerceNullStep($frame);
        }
        // php-src Z_PARAM_NUMBER: bool coerces to 0/1 before zero-step check (#29505).
        if (Variable::TYPE_BOOLEAN === $stepVar->type) {
            $step = $stepVar->toBool() ? 1 : 0;
            if (0 === $step) {
                throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
            }

            return self::normalizeIntStepSign($start, $end, $step);
        }
        if (Variable::TYPE_INTEGER !== $stepVar->type) {
            if (Variable::TYPE_FLOAT === $stepVar->type) {
                $step = VmMath::floatToZendLong($stepVar->toFloat());
                if (0 === $step) {
                    throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
                }

                return self::normalizeIntStepSign($start, $end, $step);
            }
            if (Variable::TYPE_STRING === $stepVar->type) {
                $s = $stepVar->toString();
                if ('' !== $s && is_numeric($s)) {
                    if (str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E')) {
                        $step = VmMath::floatToZendLong((float) $s);
                    } else {
                        $step = (int) $s;
                    }
                    if (0 === $step) {
                        throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
                    }

                    return self::normalizeIntStepSign($start, $end, $step);
                }
            }
            throw new \TypeError(
                sprintf(
                    'range(): Argument #3 ($step) must be of type int|float, %s given',
                    VmStreamArg::debugTypeName($stepVar)
                )
            );
        }
        $step = $stepVar->toInt();
        if (0 === $step) {
            throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
        }

        return self::normalizeIntStepSign($start, $end, $step);
    }

    private static function normalizeIntStepSign(int $start, int $end, int $step): int
    {
        // php-src negative_step_error: strictly increasing only (#29351).
        if ($start < $end && $step < 0) {
            if (CompilerVersion::supportsRangeIncreasingNegativeStepError()) {
                throw new \ValueError(RangeIntJitHelper::stepIncreasingNegativeErrorMessage());
            }

            return abs($step);
        }
        if ($start > $end && $step > 0) {
            return -abs($step);
        }

        return $step;
    }

    private static function resolveFloatStep(
        ?Variable $stepVar,
        float $start,
        float $end,
        ?Frame $frame = null
    ): float {
        if (null === $stepVar) {
            return $start <= $end ? 1.0 : -1.0;
        }
        if (Variable::TYPE_NULL === $stepVar->type) {
            self::rejectOrCoerceNullStep($frame);
        }
        // php-src Z_PARAM_NUMBER: bool coerces to 0.0/1.0 (#29505).
        if (Variable::TYPE_BOOLEAN === $stepVar->type) {
            $step = $stepVar->toBool() ? 1.0 : 0.0;
        } elseif (Variable::TYPE_INTEGER === $stepVar->type) {
            $step = (float) $stepVar->toInt();
        } elseif (Variable::TYPE_FLOAT === $stepVar->type) {
            $step = $stepVar->toFloat();
        } elseif (Variable::TYPE_STRING === $stepVar->type) {
            $s = $stepVar->toString();
            if ('' === $s || !is_numeric($s)) {
                throw new \TypeError(
                    sprintf(
                        'range(): Argument #3 ($step) must be of type int|float, %s given',
                        VmStreamArg::debugTypeName($stepVar)
                    )
                );
            }
            $step = (float) $s;
        } else {
            throw new \TypeError(
                sprintf(
                    'range(): Argument #3 ($step) must be of type int|float, %s given',
                    VmStreamArg::debugTypeName($stepVar)
                )
            );
        }
        if (0.0 === $step) {
            throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
        }

        return self::normalizeFloatStepSign($start, $end, $step);
    }

    private static function normalizeFloatStepSign(float $start, float $end, float $step): float
    {
        // php-src negative_step_error: strictly increasing only (#29351).
        if ($start < $end && $step < 0.0) {
            if (CompilerVersion::supportsRangeIncreasingNegativeStepError()) {
                throw new \ValueError(RangeIntJitHelper::stepIncreasingNegativeErrorMessage());
            }

            return abs($step);
        }
        if ($start > $end && $step > 0.0) {
            return -abs($step);
        }

        return $step;
    }

    private static function resolveCharStep(
        ?Variable $stepVar,
        string $startChar,
        string $endChar,
        ?Frame $frame = null
    ): int {
        if (null === $stepVar) {
            return ord($startChar) > ord($endChar) ? -1 : 1;
        }
        if (Variable::TYPE_NULL === $stepVar->type) {
            self::rejectOrCoerceNullStep($frame);
        }
        // php-src Z_PARAM_NUMBER: bool coerces to 0/1 (#29505).
        if (Variable::TYPE_BOOLEAN === $stepVar->type) {
            $step = $stepVar->toBool() ? 1 : 0;
        } elseif (Variable::TYPE_INTEGER !== $stepVar->type) {
            if (Variable::TYPE_FLOAT === $stepVar->type) {
                $step = VmMath::floatToZendLong($stepVar->toFloat());
            } elseif (Variable::TYPE_STRING === $stepVar->type) {
                $s = $stepVar->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(
                        sprintf(
                            'range(): Argument #3 ($step) must be of type int, %s given',
                            VmStreamArg::debugTypeName($stepVar)
                        )
                    );
                }
                $step = str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E')
                    ? VmMath::floatToZendLong((float) $s)
                    : (int) $s;
            } else {
                throw new \TypeError(
                    sprintf(
                        'range(): Argument #3 ($step) must be of type int, %s given',
                        VmStreamArg::debugTypeName($stepVar)
                    )
                );
            }
        } else {
            $step = $stepVar->toInt();
        }
        if (0 === $step) {
            throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
        }

        return self::normalizeIntStepSign(ord($startChar), ord($endChar), $step);
    }

    /** Int range list for JIT/AOT helpers (#13502) — SSOT {@see RangeIntJitHelper::intRangeCopy()}. */
    public static function intRangeTable(int $start, int $end, int $step): HashTable
    {
        return RangeIntJitHelper::intRangeCopy($start, $end, $step);
    }

    /**
     * php-src ext/standard/array.c — non-finite start/end → ValueError (#27927).
     * Zend 8.2 formats ±INF as "inf" and finite floats via %0.0f.
     */
    private static function rejectNonFiniteBounds(float $start, float $end): void
    {
        if (\is_finite($start) && \is_finite($end)) {
            return;
        }
        throw new \ValueError(\sprintf(
            'Invalid range supplied: start=%s end=%s',
            self::formatBoundForInvalidRange($start),
            self::formatBoundForInvalidRange($end)
        ));
    }

    private static function formatBoundForInvalidRange(float $value): string
    {
        if (!\is_finite($value)) {
            return 'inf';
        }

        return \sprintf('%0.0f', $value);
    }

    /**
     * php-src PHP_FUNCTION(range) / boundary_error: when endpoints differ, |step| must be
     * <= |end - start| (equality allowed). Equal endpoints always yield a singleton (#26657).
     */
    private static function rejectOversizedIntStep(int $start, int $end, int $step): void
    {
        if ($start === $end) {
            return;
        }
        $span = $start > $end ? ($start - $end) : ($end - $start);
        $stepAbs = $step < 0 ? -$step : $step;
        if ($span < $stepAbs) {
            throw new \ValueError(RangeIntJitHelper::stepOversizedErrorMessage());
        }
    }

    private static function rejectOversizedFloatStep(float $start, float $end, float $step): void
    {
        if ($start === $end) {
            return;
        }
        $stepAbs = abs($step);
        if ($stepAbs <= 0.0) {
            throw new \ValueError(RangeIntJitHelper::stepZeroErrorMessage());
        }
        $span = $start > $end ? ($start - $end) : ($end - $start);
        if ($span < $stepAbs) {
            throw new \ValueError(RangeIntJitHelper::stepOversizedErrorMessage());
        }
    }

    private static function buildIntRange(int $start, int $end, int $step): HashTable
    {
        return RangeIntJitHelper::buildIntRange($start, $end, $step);
    }

    private static function buildFloatRange(float $start, float $end, float $step): HashTable
    {
        self::rejectNonFiniteBounds($start, $end);
        self::rejectOversizedFloatStep($start, $end, $step);
        $ht = new HashTable();
        $index = 0;
        // php-src ext/standard/array.c — index * step from start, size from round span (#15326).
        if ($step > 0.0) {
            if ($end < $start) {
                return $ht;
            }
            $size = (int) \round((($end - $start) / $step) + 1.0, \PHP_ROUND_HALF_UP);
            for ($i = 0; $i < $size; ++$i) {
                $element = $start + ($i * $step);
                if ($element > $end) {
                    break;
                }
                $stored = new Variable();
                $stored->float($element);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        } else {
            if ($end > $start) {
                return $ht;
            }
            $stepAbs = abs($step);
            $size = (int) \round((($start - $end) / $stepAbs) + 1.0, \PHP_ROUND_HALF_UP);
            for ($i = 0; $i < $size; ++$i) {
                $element = $start + ($i * $step);
                if ($element < $end) {
                    break;
                }
                $stored = new Variable();
                $stored->float($element);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        }

        return $ht;
    }

    private static function buildCharRange(string $startChar, string $endChar, int $step): HashTable
    {
        $start = ord($startChar);
        $end = ord($endChar);
        self::rejectOversizedIntStep($start, $end, $step);
        $ht = new HashTable();
        $index = 0;
        if ($step > 0) {
            for ($i = $start; $i <= $end; $i += $step) {
                $stored = new Variable();
                $stored->string(chr($i));
                $ht->addIndex($index, $stored);
                ++$index;
            }
        } else {
            for ($i = $start; $i >= $end; $i += $step) {
                $stored = new Variable();
                $stored->string(chr($i));
                $ht->addIndex($index, $stored);
                ++$index;
            }
        }

        return $ht;
    }
}
