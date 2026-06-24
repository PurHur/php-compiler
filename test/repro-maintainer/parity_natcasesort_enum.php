<?php

declare(strict_types=1);

enum Color: int
{
    case Green = 2;
    case Red = 1;
}

$a = [Color::Green, Color::Red];
try {
    natcasesort($a);
    echo "uncaught\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
