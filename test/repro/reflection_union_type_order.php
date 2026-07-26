<?php
// Issue #23487 — ReflectionUnionType::__toString / getTypes order (php-src-strict)
function f(int|string|float $x): void {}
function g(string|int|float $x): void {}
echo (string) (new ReflectionFunction('f'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('g'))->getParameters()[0]->getType(), "\n";
$types = (new ReflectionFunction('f'))->getParameters()[0]->getType()->getTypes();
echo implode(',', array_map(strval(...), $types)), "\n";
