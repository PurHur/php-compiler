--TEST--
Language: continue 1.5 is compile-time fatal (#32207, Zend/zend_compile.c)
--FILE--
<?php
for ($i = 0; $i < 1; $i++) {
    continue 1.5;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  'continue' operator accepts only positive integers in %s on line %d
