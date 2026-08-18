--TEST--
Language: break 0 is compile-time fatal (#32155, Zend/zend_compile.c)
--FILE--
<?php
for ($i = 0; $i < 1; $i++) {
    break 0;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  'break' operator accepts only positive integers in %s on line %d
