--TEST--
Language: never in parameter union — compile fatal (#6967, zend_compile.c)
--FILE--
<?php
function f(int|never $x) {}
echo "compiled\n";
--EXPECT_EXIT--
255
