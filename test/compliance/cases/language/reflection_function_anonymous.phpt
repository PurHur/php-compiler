--TEST--
Language: ReflectionFunction::isAnonymous() — closure vs named function (VM, #4123)
--FILE--
<?php
$fn = function (): int { return 1; };
$ref = new ReflectionFunction($fn);
echo $ref->isAnonymous() ? "anon\n" : "named\n";

function named_control(): void {}
$ref2 = new ReflectionFunction('named_control');
echo $ref2->isAnonymous() ? "anon\n" : "named\n";
--EXPECT--
anon
named
