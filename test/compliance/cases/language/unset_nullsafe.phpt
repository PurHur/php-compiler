--TEST--
Language: unset() on nullsafe ?-> chain — compile-time fatal (#4983, zend_compile.c)
--FILE--
<?php
$o = null;
unset($o?->x);
echo "ran\n";
--EXPECT_EXIT--
255
