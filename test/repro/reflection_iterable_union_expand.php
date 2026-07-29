<?php
// Issue #25065 — iterable in ReflectionUnionType expands to Traversable|array (php-src-strict)
function f(): iterable|false
{
    return false;
}
function g(iterable $x): void
{
}
function h(?iterable $x): void
{
}
function i(iterable|string $x): void
{
}
function j(iterable|null $x): void
{
}

$rt = (new ReflectionFunction('f'))->getReturnType();
echo (string) $rt, "\n";
if ($rt instanceof ReflectionUnionType) {
    foreach ($rt->getTypes() as $t) {
        echo 'part:', (string) $t, "\n";
    }
}

echo 'bare=', (string) (new ReflectionFunction('g'))->getParameters()[0]->getType(), "\n";
echo 'null=', (string) (new ReflectionFunction('h'))->getParameters()[0]->getType(), "\n";
echo 'str=', (string) (new ReflectionFunction('i'))->getParameters()[0]->getType(), "\n";
echo 'ornull=', (string) (new ReflectionFunction('j'))->getParameters()[0]->getType(), "\n";
