<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    http_build_query([E::A => 'v']);
    echo "uncaught\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
