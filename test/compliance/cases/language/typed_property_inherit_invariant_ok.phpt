--TEST--
Language: identical typed property redeclaration inherits cleanly (#23505, zend_inheritance.c)
--FILE--
<?php
class A { public stdClass $x; }
class B extends A { public stdClass $x; }
class C { public self $y; }
class D extends C { public self $y; }
echo "ok\n";
--EXPECT--
ok
