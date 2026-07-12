<?php
declare(strict_types=1);

enum Color: string {
    case Red = 'red';
}

echo 'tryfrom:' . var_export(Color::tryFrom('green'), true) . "\n";

$tmp = Color::tryFrom('green');
echo 'assign:' . var_export($tmp, true) . "\n";
