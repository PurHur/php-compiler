--TEST--
Language: unimplemented abstract parent Fatal cites Parent::method via eval (#30022)
--INI--
display_errors=1
--FILE--
<?php
abstract class A { abstract function f(); }
eval('class B extends A {}');
--EXPECTF--
Fatal error: Class B contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (A::f) in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
