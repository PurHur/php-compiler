<?php

declare(strict_types=1);

enum E: string
{
    case A = 'hi';
}

try {
    gzcompress(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
