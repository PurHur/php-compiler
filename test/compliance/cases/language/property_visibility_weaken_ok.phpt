--TEST--
Language: child may weaken property visibility (#25661, Zend/zend_inheritance.c)
--FILE--
<?php
class A { protected $x = 1; }
class B extends A { public $x = 2; }
echo "LOADED\n";
--EXPECT--
LOADED
