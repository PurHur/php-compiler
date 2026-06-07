<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    get_class_vars(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
