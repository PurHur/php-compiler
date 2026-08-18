--TEST--
Language: const TRUE preserves source spelling in compile fatal (#32228, Zend/zend_compile.c)
--FILE--
<?php
const TRUE = 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot redeclare constant 'TRUE' in %s on line %d
