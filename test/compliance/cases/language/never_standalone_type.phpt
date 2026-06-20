--TEST--
Language: nullable ?never parameter — compile fatal (#10220, zend_compile.c)
--FILE--
<?php
function f(?never $x = null): void {}
echo "ok\n";
--EXPECT_EXIT--
255
