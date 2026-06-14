<?php declare(strict_types=1);

function f(?string $name): ?string
{
    return null === $name ? null : $name;
}

echo f(null) === null ? 'null' : 'ok';
echo "\n";
echo f('hello') . "\n";
