<?php
// Issue #23065 — ReflectionType::__toString DNF parens + int|null → ?int (php-src-strict)
function f((Traversable&Countable)|array $x) {}
function g(int|null $x) {}
function h(?int $x) {}
echo (string) (new ReflectionFunction('f'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('g'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('h'))->getParameters()[0]->getType(), "\n";
