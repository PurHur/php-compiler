--TEST--
Language: ?mixed parameter — compile fatal (#26554, zend_compile.c)
--FILE--
<?php
function f(?mixed $x) {
    echo "ran\n";
}
f(null);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Type mixed cannot be marked as nullable since mixed already includes null
