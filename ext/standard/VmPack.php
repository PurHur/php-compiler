<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** VM pack()/unpack() — pack via PackEngine; unpack via UnpackEngine (issues #5231, #5442). */
final class VmPack
{
    /**
     * @param list<mixed> $args values after format string
     */
    public static function pack(string $format, array $args): string
    {
        return PackEngine::pack($format, $args);
    }

    /**
     * @return array<int|string, int|float|string>|false
     */
    public static function unpack(string $format, string $data, int $offset = 0): array|false
    {
        return UnpackEngine::unpack($format, $data, $offset);
    }
}
