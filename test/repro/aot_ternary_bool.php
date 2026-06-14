<?php declare(strict_types=1);

function f(string $p): ?string
{
    return true ? $p : null;
}

echo f(__FILE__) ?? 'null';
echo "\n";
