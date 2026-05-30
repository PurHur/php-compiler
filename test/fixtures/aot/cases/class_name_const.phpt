--TEST--
AOT: ClassName::class and $object::class (#740, #3547)
--FILE--
<?php
class Router {}
echo Router::class;
echo "\n";
class C {}
$o = new C();
echo $o::class;
echo "\n";
class Parent_ {}
class Child extends Parent_ {}
$b = new Child();
echo $b::class;
echo "\n";
--EXPECT--
Router
C
Child
