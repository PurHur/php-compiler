--TEST--
ReflectionType::__toString DNF parentheses and int|null → ?int (#23065)
--FILE--
<?php
interface A {}
interface B {}
interface C {}
interface D {}
function f((Traversable&Countable)|array $x) {}
function g(int|null $x) {}
function h(?int $x) {}
function i(null|string $x) {}
function j((A&B)|null $x) {}
function k(A|(B&C)|D $x) {}
echo (string) (new ReflectionFunction('f'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('g'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('h'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('i'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('j'))->getParameters()[0]->getType(), "\n";
echo (string) (new ReflectionFunction('k'))->getParameters()[0]->getType(), "\n";
?>
--EXPECT--
(Traversable&Countable)|array
?int
?int
?string
(A&B)|null
A|(B&C)|D
