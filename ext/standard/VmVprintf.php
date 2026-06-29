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
     * @throws \TypeError when {@param $argsVar} is not an array (php-src ext/standard/sprintf.c, #13589)
     */
    public static function requireValuesArray(Variable $argsVar, string $fn): void
    {
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($values) must be of type array, %s given',
                $fn,
                VmParseStr::zendTypeLabel($argsVar)
            ));
        }
    }

    /**
     * @return list<Variable>
     */
    public static function argsFromArray(Variable $arrayVar, string $fn = 'vprintf'): array
    {
        self::requireValuesArray($arrayVar, $fn);
        $values = [];
        foreach ($arrayVar->toArray()->iterate(true) as $value) {
            $values[] = $value->resolveIndirect();
        }

        return $values;
    }

    public static function formatString(string $format, Variable $argsVar, ?Frame $frame = null, string $fn = 'vprintf'): string
    {
        return VmSprintf::format($format, self::argsFromArray($argsVar, $fn), $frame);
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
        $formatted = self::formatString($format, $argsVar, $frame, 'vfprintf');
        $written = VmFs::fwrite($handle, $formatted, null);
        if (false === $written) {
            throw new \LogicException('vfprintf() failed to write to stream in this compiler build');
        }

        return $written;
    }
}
