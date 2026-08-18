--TEST--
Language: continue 2 over-deep is compile-time fatal (#32207, Zend/zend_compile.c)
--FILE--
<?php
for ($i = 0; $i < 1; $i++) {
    continue 2;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot 'continue' 2 levels in %s on line %d
