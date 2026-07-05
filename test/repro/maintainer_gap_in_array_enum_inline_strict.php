<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    var_dump(in_array(E::A, [E::A], true));
    var_dump(array_search(E::A, [E::A], true));
    echo "in_array_enum_inline_strict_ok=1\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}