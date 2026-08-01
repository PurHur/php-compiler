--TEST--
Language: bool|true union type — runtime fatal, Duplicate type true is redundant (#26555, zend_compile.c)
--FILE--
<?php
function f(bool|true $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Duplicate type true is redundant in %s on line %d
