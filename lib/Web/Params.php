<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\is_numeric;
use PHPCompiler\VM\Variable;

/**
 * Query/body param coercion for compiled web apps (issue #157).
 *
 * Invalid or missing keys return the default without notice.
 */
final class Params
{
    private const BOOL_TRUE = ['1', 'true', 'on', 'yes'];
    private const BOOL_FALSE = ['0', 'false', 'off', 'no', ''];

    public static function coerceInt(
        Variable $source,
        string $key,
        int $default,
        ?int $min = null,
        ?int $max = null
    ): int {
        $raw = self::readStringValue($source, $key);
        if (null === $raw) {
            return $default;
        }
        if (!is_numeric::isNumeric($raw)) {
            return $default;
        }
        $n = self::numericToInt($raw);
        if (null !== $min && $n < $min) {
            $n = $min;
        }
        if (null !== $max && $n > $max) {
            $n = $max;
        }

        return $n;
    }

    public static function coerceString(
        Variable $source,
        string $key,
        string $default = '',
        ?int $maxLen = null
    ): string {
        $raw = self::readStringValue($source, $key);
        if (null === $raw) {
            return $default;
        }
        $s = match ($raw->type) {
            Variable::TYPE_STRING => $raw->toString(),
            Variable::TYPE_INTEGER => (string) $raw->toInt(),
            Variable::TYPE_BOOLEAN => $raw->toBool() ? '1' : '0',
            default => '',
        };
        $s = trim($s);
        if (null !== $maxLen && $maxLen >= 0 && strlen($s) > $maxLen) {
            return substr($s, 0, $maxLen);
        }

        return $s;
    }

    public static function coerceBool(
        Variable $source,
        string $key,
        bool $default = false
    ): bool {
        $raw = self::readStringValue($source, $key);
        if (null === $raw) {
            return $default;
        }
        if (Variable::TYPE_BOOLEAN === $raw->type) {
            return $raw->toBool();
        }
        if (Variable::TYPE_INTEGER === $raw->type) {
            return 0 !== $raw->toInt();
        }
        if (Variable::TYPE_STRING !== $raw->type) {
            return $default;
        }
        $lower = strtolower($raw->toString());
        if (in_array($lower, self::BOOL_TRUE, true)) {
            return true;
        }
        if (in_array($lower, self::BOOL_FALSE, true)) {
            return false;
        }

        return $default;
    }

    private static function readStringValue(Variable $source, string $key): ?Variable
    {
        if (Variable::TYPE_ARRAY !== $source->type) {
            throw new \LogicException('web_*() first argument must be an array in this compiler build');
        }
        $ht = $source->toArray();
        $keyVar = new Variable();
        $keyVar->string($key);
        if (!$ht->offsetIsSet($keyVar)) {
            return null;
        }
        $stored = $ht->find($key);
        if (null === $stored) {
            return null;
        }
        $value = $stored->resolveIndirect();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return null;
        }

        return $value;
    }

    private static function numericToInt(Variable $v): int
    {
        switch ($v->type) {
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return (int) $v->toFloat();
            case Variable::TYPE_STRING:
                return (int) $v->toString();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool() ? 1 : 0;
            default:
                throw new \LogicException('web_int() value must be numeric in this compiler build');
        }
    }
}
