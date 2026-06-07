<?php

declare(strict_types=1);

enum E: string
{
    case A = '1.0';
}

try {
    version_compare(E::A, '1.0');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
