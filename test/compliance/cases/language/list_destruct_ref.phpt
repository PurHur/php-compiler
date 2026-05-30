--TEST--
list / [] destructuring with & ref from non-referenceable RHS (issue #3799, Zend zend_compile.c)
--FILE--
<?php
$a = 1;
$b = 2;
[$x, &$y] = [$a, $b];
--EXPECT_EXIT--
255
