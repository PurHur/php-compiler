--TEST--
Language: ?mixed property — compile fatal (#26554, zend_compile.c)
--FILE--
<?php
class C {
    public ?mixed $x;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Type mixed cannot be marked as nullable since mixed already includes null
