--TEST--
Language: standalone never parameter type — compile fatal (#11473, zend_compile.c)
--FILE--
<?php
function acceptsNever(never $value): void {}
echo "ok\n";
--EXPECT_EXIT--
255
