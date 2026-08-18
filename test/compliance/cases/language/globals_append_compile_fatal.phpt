--TEST--
Language: $GLOBALS[] = is compile-time fatal (#32253, Zend/zend_compile.c)
--FILE--
<?php
$GLOBALS[] = 1;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot append to $GLOBALS in %s on line %d
