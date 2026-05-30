--TEST--
Stdlib: class_implements() — optional $autoload boolean (VM, #3748)
--FILE--
<?php
interface I {}
class C implements I {}
$byObject = class_implements(new C, false);
$byClass = class_implements(C::class, false);
echo isset($byObject['I']) ? '1' : '0';
echo isset($byClass['I']) ? '1' : '0';
echo "\n";
--EXPECT--
11
