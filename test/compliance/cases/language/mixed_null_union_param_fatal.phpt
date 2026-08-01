--TEST--
Language: mixed|null parameter — compile fatal standalone (#26554, zend_compile.c)
--FILE--
<?php
function f(mixed|null $x) {
    echo "ran\n";
}
f(null);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Type mixed can only be used as a standalone type
