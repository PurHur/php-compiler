--TEST--
Language: list()/[] cannot mix keyed and unkeyed destructuring slots (#14879, Zend/zend_compile.c)
--FILE--
<?php
list(0 => $x, $y) = [1, 2];
echo "ran\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot mix keyed and unkeyed array entries in assignments
