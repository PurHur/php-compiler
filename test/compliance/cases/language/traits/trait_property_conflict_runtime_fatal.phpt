--TEST--
Language: incompatible trait/class property — runtime fatal not compile (#17995, zend_inheritance.c)
--FILE--
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: C and T define the same property ($x) in the composition of C. However, the definition differs and is considered incompatible. Class was composed in %s on line %d
