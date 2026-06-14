<?php declare(strict_types=1);

function f(string $path): ?string
{
    return is_file($path) ? $path : null;
}

echo f(__FILE__) ?? 'null';
echo "\n";
