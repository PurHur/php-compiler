--TEST--
Language: const null is compile-time fatal (#32228, Zend/zend_compile.c)
--FILE--
<?php
const null = 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot redeclare constant 'null' in %s on line %d
