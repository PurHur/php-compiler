<?php
declare(strict_types=1);

enum E: int
{
    case A = 1;
}

set_error_handler(static function (int $errno, string $errstr): bool {
    echo $errstr, "\n";
    return true;
});

compact(E::A);
