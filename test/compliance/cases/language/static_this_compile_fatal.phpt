--TEST--
Language: static $this is compile-time fatal (#32181, Zend/zend_compile.c)
--FILE--
<?php
function foo() { static $this; }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as static variable in %s on line %d
