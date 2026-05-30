<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** VM pack()/unpack() via host PHP (parity with PHP 8.2). */
final class VmPack
{
    /**
     * @param list<mixed> $args values after format string
     */
    public static function pack(string $format, array $args): string
    {
        return \call_user_func_array('pack', array_merge([$format], $args));
    }

    /**
     * @return array<int|string, int|float|string>|false
     */
    public static function unpack(string $format, string $data, int $offset = 0): array|false
    {
        $result = @\unpack($format, $data, $offset);
        if (false === $result) {
            return false;
        }

        return $result;
    }
}
