--TEST--
Language: const true is compile-time fatal (#32228, Zend/zend_compile.c)
--FILE--
<?php
const true = 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot redeclare constant 'true' in %s on line %d
