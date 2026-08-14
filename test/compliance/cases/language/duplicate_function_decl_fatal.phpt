--TEST--
Language: duplicate function declaration — Cannot redeclare f() (#31109, Zend/zend_compile.c)
--FILE--
<?php
function f() {}
function f() {}
echo "REACHED\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot redeclare f() (previously declared in %s:%d) in %s on line %d
