<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    number_format(1.0, E::A);
} catch (TypeError $e) {
    echo 'decimals: ', $e->getMessage(), "\n";
}
try {
    number_format(1.0, 2, E::A);
} catch (TypeError $e) {
    echo 'decimal_separator: ', $e->getMessage(), "\n";
}
