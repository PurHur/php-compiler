<?php

declare(strict_types=1);

class C {
    public static int $x = 1;
}

$fn = static function (): int {
    return self::$x;
};

echo ($fn->bindTo(null, C::class))(), "\n";
