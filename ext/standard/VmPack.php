<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** VM pack() via host PHP (parity with PHP 8.2). */
final class VmPack
{
    /**
     * @param list<mixed> $args values after format string
     */
    public static function pack(string $format, array $args): string
    {
        return \pack($format, ...$args);
    }
}
