--TEST--
Stdlib: class_uses() — optional $autoload boolean (VM, #3748)
--FILE--
<?php
trait T {}
class C {
    use T;
}
$byObject = class_uses(new C, false);
$byClass = class_uses(C::class, false);
echo isset($byObject['T']) ? '1' : '0';
echo isset($byClass['T']) ? '1' : '0';
echo "\n";
--EXPECT--
11
