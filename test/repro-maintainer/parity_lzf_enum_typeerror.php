<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

enum E: string
{
    case X = 'x';
}

try {
    lzf_compress(E::X);
    echo "uncaught\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
