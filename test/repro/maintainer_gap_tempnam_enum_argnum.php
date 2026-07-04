<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    tempnam(sys_get_temp_dir(), E::A);
    echo "prefix uncaught\n";
} catch (TypeError $e) {
    echo 'prefix: ', $e->getMessage(), "\n";
}

try {
    tempnam(E::A, 'p');
    echo "directory uncaught\n";
} catch (TypeError $e) {
    echo 'directory: ', $e->getMessage(), "\n";
}
