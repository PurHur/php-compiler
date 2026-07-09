--TEST--
stdlib compact() enum operand warning names enum class (#17481, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int
{
    case A = 1;
}

set_error_handler(static function (int $severity, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

compact(E::A);
--EXPECT--
W:compact(): Argument #1 must be string or array of strings, E given
