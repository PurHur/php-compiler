<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    echo 'x' . E::A;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo E::A->name, '|', E::A->value, "\n";
