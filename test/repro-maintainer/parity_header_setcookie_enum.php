<?php

declare(strict_types=1);

enum E: string
{
    case A = 'v';
}

try {
    header_remove('X-Test', 'extra');
    echo "header_remove uncaught\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    setcookie('n', E::A);
    echo "setcookie uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
