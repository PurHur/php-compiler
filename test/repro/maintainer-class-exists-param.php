<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    class_exists(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
