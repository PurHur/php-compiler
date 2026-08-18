--TEST--
Language: break 2 over-deep is compile-time fatal (#32207, Zend/zend_compile.c)
--FILE--
<?php
for ($i = 0; $i < 1; $i++) {
    break 2;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot 'break' 2 levels in %s on line %d
