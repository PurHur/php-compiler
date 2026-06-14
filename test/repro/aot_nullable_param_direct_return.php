<?php declare(strict_types=1);

function f(?string $n): ?string
{
    return $n;
}

echo f('hello'), "\n";
