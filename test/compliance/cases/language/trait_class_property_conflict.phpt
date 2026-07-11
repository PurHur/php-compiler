--TEST--
Language: trait/class incompatible instance property — Zend fatal (#11834, zend_inheritance.c)
--FILE--
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: C and T define the same property ($x) in the composition of C. However, the definition differs and is considered incompatible. Class was composed
