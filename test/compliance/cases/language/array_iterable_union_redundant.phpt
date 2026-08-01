--TEST--
Language: array|iterable union type — runtime fatal, Duplicate type array is redundant (#26564, zend_compile.c)
--FILE--
<?php
function f(array|iterable $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Duplicate type array is redundant in %s on line %d
