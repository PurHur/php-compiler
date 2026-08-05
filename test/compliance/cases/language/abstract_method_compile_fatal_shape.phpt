--TEST--
Language: abstract method compile fatal emits Zend-shaped "Fatal error:" not "parseAndCompile failure:" (#27718)
--FILE--
<?php
abstract class A { abstract function f(); }
class B extends A {}
--EXPECTF--
Fatal error: Class B contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (A::f) in %s on line %d
