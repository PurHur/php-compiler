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
     * @return list<Variable>
     */
    public static function argsFromArray(Variable $arrayVar): array
    {
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            throw new \LogicException('vprintf() args must be an array in this compiler build');
        }
        $values = [];
        foreach ($arrayVar->toArray()->iterate(true) as $value) {
            $values[] = $value->resolveIndirect();
        }

        return $values;
    }

    public static function formatString(string $format, Variable $argsVar, ?Frame $frame = null): string
    {
        return VmSprintf::format($format, self::argsFromArray($argsVar), $frame);
    }

    public static function vprintf(string $format, Variable $argsVar, ?Frame $frame = null): int
    {
        $formatted = self::formatString($format, $argsVar, $frame);
        OutputBuffer::append($formatted);
        $written = VmString::byteLength($formatted);
        if ($written <= 0) {
            throw new \LogicException('vprintf() failed to write to stdout in this compiler build');
        }

        return $written;
    }

    public static function vfprintf(int $handle, string $format, Variable $argsVar, ?Frame $frame = null): int
    {
        $formatted = self::formatString($format, $argsVar, $frame);
        $written = VmFs::fwrite($handle, $formatted, null);
        if (false === $written) {
            throw new \LogicException('vfprintf() failed to write to stream in this compiler build');
        }

        return $written;
    }
}
