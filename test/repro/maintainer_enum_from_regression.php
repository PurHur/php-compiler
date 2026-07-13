<?php

declare(strict_types=1);

enum Count: int
{
    case One = 1;
}

enum Color: string
{
    case Red = 'red';
}

try {
    Count::tryFrom('1');
    echo "tryFrom int string uncaught\n";
} catch (TypeError $e) {
    echo 'tryFrom int string: ', $e->getMessage(), "\n";
}

try {
    Color::tryFrom(1);
    echo "tryFrom string int uncaught\n";
} catch (TypeError $e) {
    echo 'tryFrom string int: ', $e->getMessage(), "\n";
}

var_dump(Count::tryFrom(1));
