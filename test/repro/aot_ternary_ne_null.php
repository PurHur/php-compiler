<?php declare(strict_types=1);

function f(?string $name): ?string
{
    return null !== $name ? $name : null;
}

echo f('hello') . "\n";
