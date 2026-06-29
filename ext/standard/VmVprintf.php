<?php

declare(strict_types=1);

/**
 * VM helpers for vprintf()/vfprintf() — sprintf formatting + stream write.
 *
 * php-src: ext/standard/formatted_print.c — PHP_FUNCTION(vprintf), PHP_FUNCTION(vfprintf)
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;

final class VmVprintf
{
    /**
     * Z_PARAM_ARRAY values arg (php-src ext/standard/sprintf.c; #13589, #13597).
     */
    public static function requireArgsArray(Variable $arrayVar, string $function, int $argNum = 2): void
    {
        $resolved = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY === $resolved->type) {
            return;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($values) must be of type array, %s given',
            $function,
            $argNum,
            self::valueTypeLabel($resolved)
        ));
    }

    /**
     * @return list<Variable>
     */
    public static function argsFromArray(Variable $arrayVar, string $function = 'vprintf', int $argNum = 2): array
    {
        self::requireArgsArray($arrayVar, $function, $argNum);
        $values = [];
        foreach ($arrayVar->toArray()->iterate(true) as $value) {
            $values[] = $value->resolveIndirect();
        }

        return $values;
    }

    public static function formatString(
        string $format,
        Variable $argsVar,
        ?Frame $frame = null,
        string $function = 'vprintf',
        int $valuesArgNum = 2
    ): string {
        return VmSprintf::format($format, self::argsFromArray($argsVar, $function, $valuesArgNum), $frame);
    }

    public static function vprintf(string $format, Variable $argsVar, ?Frame $frame = null): int
    {
        $formatted = self::formatString($format, $argsVar, $frame, 'vprintf');
        OutputBuffer::append($formatted);
        $written = VmString::byteLength($formatted);
        if ($written <= 0) {
            throw new \LogicException('vprintf() failed to write to stdout in this compiler build');
        }

        return $written;
    }

    public static function vfprintf(int $handle, string $format, Variable $argsVar, ?Frame $frame = null): int
    {
        $formatted = self::formatString($format, $argsVar, $frame, 'vfprintf', 3);
        $written = VmFs::fwrite($handle, $formatted, null);
        if (false === $written) {
            throw new \LogicException('vfprintf() failed to write to stream in this compiler build');
        }

        return $written;
    }

    private static function valueTypeLabel(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return $value->toObject()->class->name;
            default:
                return 'mixed';
        }
    }
}
