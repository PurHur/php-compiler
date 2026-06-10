<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** VM pack()/unpack() — pack via PackEngine; unpack via UnpackEngine (issues #5231, #5442). */
final class VmPack
{
    /**
     * @param list<mixed|Variable> $args values after format string
     */
    public static function pack(string $format, array $args, ?Frame $frame = null): string
    {
        return PackEngine::pack($format, $args, $frame);
    }

    /**
     * @return array<int|string, int|float|string>|false
     */
    public static function unpack(string $format, string $data, int $offset = 0): array|false
    {
        return UnpackEngine::unpack($format, $data, $offset);
    }
}
