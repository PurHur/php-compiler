--TEST--
Language: const false is compile-time fatal (#32228, Zend/zend_compile.c)
--FILE--
<?php
const false = 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot redeclare constant 'false' in %s on line %d
