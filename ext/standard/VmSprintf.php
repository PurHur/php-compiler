<?php

declare(strict_types=1);

/**
 * VM-runtime sprintf() subset (%s, %d, %f, %%) without PHP userland builtins.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

final class VmSprintf
{
    private const MAX_OUTPUT = 4096;

    /**
     * @param list<Variable> $args
     */
    public static function format(string $format, array $args): string
    {
        $out = '';
        $argIdx = 0;
        $len = VmString::byteLength($format);
        for ($pos = 0; $pos < $len; ++$pos) {
            $ch = $format[$pos];
            if ('%' !== $ch) {
                $out .= $ch;
                continue;
            }
            if ($pos + 1 >= $len) {
                throw new \LogicException('sprintf() trailing % in format string');
            }
            $spec = $format[++$pos];
            if ('%' === $spec) {
                $out .= '%';
                continue;
            }
            if ($argIdx >= \count($args)) {
                throw new \LogicException('sprintf() too few arguments for format string');
            }
            $var = $args[$argIdx++];
            switch ($spec) {
                case 's':
                    $out .= self::argToString($var);
                    break;
                case 'd':
                    $out .= self::intToDecimal(self::argToInt($var));
                    break;
                case 'f':
                    $out .= VmNumberFormat::format(self::argToFloat($var), 6, '.', '');
                    break;
                default:
                    throw new \LogicException(
                        'sprintf() unsupported conversion specifier %'.$spec.' in this compiler build'
                    );
            }
            if (VmString::byteLength($out) > self::MAX_OUTPUT) {
                throw new \LogicException('sprintf() output exceeds maximum length in this compiler build');
            }
        }
        if ($argIdx < \count($args)) {
            throw new \LogicException('sprintf() too many arguments for format string');
        }

        return $out;
    }

    private static function argToString(Variable $var): string
    {
        switch ($var->type) {
            case Variable::TYPE_STRING:
                return $var->toString();
            case Variable::TYPE_INTEGER:
                return self::intToDecimal($var->toInt());
            case Variable::TYPE_FLOAT:
                return VmNumberFormat::format($var->toFloat(), 6, '.', '');
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? '1' : '';
            case Variable::TYPE_NULL:
                return '';
            default:
                throw new \LogicException('sprintf() %s requires a scalar value in this compiler build');
        }
    }

    private static function argToInt(Variable $var): int
    {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_NULL:
                return 0;
            case Variable::TYPE_STRING:
                return (int) $var->toString();
            default:
                throw new \LogicException('sprintf() %d requires a scalar value in this compiler build');
        }
    }

    private static function argToFloat(Variable $var): float
    {
        switch ($var->type) {
            case Variable::TYPE_FLOAT:
                return $var->toFloat();
            case Variable::TYPE_INTEGER:
                return (float) $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1.0 : 0.0;
            case Variable::TYPE_NULL:
                return 0.0;
            case Variable::TYPE_STRING:
                return (float) $var->toString();
            default:
                throw new \LogicException('sprintf() %f requires a scalar value in this compiler build');
        }
    }

    private static function intToDecimal(int $value): string
    {
        if (0 === $value) {
            return '0';
        }
        $negative = $value < 0;
        if ($negative) {
            $value = -$value;
        }
        $digits = '';
        while ($value > 0) {
            $digits = \chr(48 + ($value % 10)).$digits;
            $value = (int) ($value / 10);
        }

        return $negative ? '-'.$digits : $digits;
    }
}
