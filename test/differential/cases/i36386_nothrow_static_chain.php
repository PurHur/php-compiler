<?php

declare(strict_types=1);

/**
 * Same-class static self:: call chain — output must match Zend (#36386).
 */

final class A
{
    public static function mid(int $x): int
    {
        return self::leaf($x) + 1;
    }

    public static function leaf(int $x): int
    {
        return $x + 1;
    }

    public static function top(int $x): int
    {
        return self::mid($x) + 1;
    }
}

$s = 0;
for ($i = 0; $i < 10; ++$i) {
    $s += A::top(1);
}
echo $s, "\n";
