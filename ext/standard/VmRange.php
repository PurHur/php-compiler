<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * range() VM helpers — int/float/char paths (php-src ext/standard/array.c php_range; #4258).
 */
final class VmRange
{
    private const ZERO_STEP_ERROR = 'range(): Argument #3 ($step) must not exceed the specified range';

    public static function build(Frame $frame, Variable $startVar, Variable $endVar, ?Variable $stepVar): HashTable
    {
        $startVar = $startVar->resolveIndirect();
        $endVar = $endVar->resolveIndirect();
        $stepVar = null !== $stepVar ? $stepVar->resolveIndirect() : null;

        $startChar = self::charRangeLetter($startVar);
        $endChar = self::charRangeLetter($endVar);
        if (null !== $startChar && null !== $endChar) {
            $step = self::resolveCharStep($stepVar, $startChar, $endChar);

            return self::buildCharRange($startChar, $endChar, $step);
        }

        $useFloat = self::endpointPrefersFloat($startVar)
            || self::endpointPrefersFloat($endVar)
            || (null !== $stepVar && self::endpointPrefersFloat($stepVar));

        if ($useFloat) {
            $start = self::parseFloatEndpoint($startVar, $frame, 1, 'start');
            $end = self::parseFloatEndpoint($endVar, $frame, 2, 'end');
            $step = self::resolveFloatStep($stepVar, $start, $end);

            return self::buildFloatRange($start, $end, $step);
        }

        $start = self::parseIntEndpoint($startVar, $frame, 1, 'start');
        $end = self::parseIntEndpoint($endVar, $frame, 2, 'end');
        $step = self::resolveIntStep($stepVar, $start, $end);

        return self::buildIntRange($start, $end, $step);
    }

    private static function charRangeLetter(Variable $var): ?string
    {
        if (Variable::TYPE_STRING !== $var->type) {
            return null;
        }
        $s = $var->toString();
        if (1 !== \strlen($s)) {
            return null;
        }
        if (is_numeric($s)) {
            return null;
        }

        return $s;
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

        throw new \TypeError(
            sprintf(
                'range(): Argument #%d ($%s) must be of type int|float|string, %s given',
                $argIndex,
                $paramName,
                VmStreamArg::debugTypeName($var)
            )
        );
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

        throw new \TypeError(
            sprintf(
                'range(): Argument #%d ($%s) must be of type int|float|string, %s given',
                $argIndex,
                $paramName,
                VmStreamArg::debugTypeName($var)
            )
        );
    }

    private static function resolveIntStep(?Variable $stepVar, int $start, int $end): int
    {
        if (null === $stepVar) {
            return $start > $end ? -1 : 1;
        }
        if (Variable::TYPE_INTEGER !== $stepVar->type) {
            if (Variable::TYPE_FLOAT === $stepVar->type) {
                return VmMath::floatToZendLong($stepVar->toFloat());
            }
            if (Variable::TYPE_STRING === $stepVar->type) {
                $s = $stepVar->toString();
                if ('' !== $s && is_numeric($s)) {
                    if (str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E')) {
                        return VmMath::floatToZendLong((float) $s);
                    }

                    return (int) $s;
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
            throw new \ValueError(self::ZERO_STEP_ERROR);
        }

        return $step;
    }

    private static function resolveFloatStep(?Variable $stepVar, float $start, float $end): float
    {
        if (null === $stepVar) {
            return $start <= $end ? 1.0 : -1.0;
        }
        if (Variable::TYPE_INTEGER === $stepVar->type) {
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
            throw new \ValueError(self::ZERO_STEP_ERROR);
        }

        return $step;
    }

    private static function resolveCharStep(?Variable $stepVar, string $startChar, string $endChar): int
    {
        if (null === $stepVar) {
            return ord($startChar) > ord($endChar) ? -1 : 1;
        }
        if (Variable::TYPE_INTEGER !== $stepVar->type) {
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
            throw new \ValueError(self::ZERO_STEP_ERROR);
        }

        return $step;
    }

    /** Int range list for JIT/AOT helpers (#13502). */
    public static function intRangeTable(int $start, int $end, int $step): HashTable
    {
        return self::buildIntRange($start, $end, $step);
    }

    private static function buildIntRange(int $start, int $end, int $step): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        if ($step > 0) {
            for ($i = $start; $i <= $end; $i += $step) {
                $stored = new Variable();
                $stored->int($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        } else {
            for ($i = $start; $i >= $end; $i += $step) {
                $stored = new Variable();
                $stored->int($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        }

        return $ht;
    }

    private static function buildFloatRange(float $start, float $end, float $step): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        if ($step > 0.0) {
            for ($i = $start; $i <= $end; $i += $step) {
                $stored = new Variable();
                $stored->float($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        } else {
            for ($i = $start; $i >= $end; $i += $step) {
                $stored = new Variable();
                $stored->float($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        }

        return $ht;
    }

    private static function buildCharRange(string $startChar, string $endChar, int $step): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        $start = ord($startChar);
        $end = ord($endChar);
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
