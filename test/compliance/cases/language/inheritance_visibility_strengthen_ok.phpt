--TEST--
Language: visibility strengthen protected→public allowed (zend_inheritance.c, #25634)
--FILE--
<?php
class A2 { protected function f() {} }
class B2 extends A2 { public function f() {} }
echo "ok\n";
--EXPECT--
ok
