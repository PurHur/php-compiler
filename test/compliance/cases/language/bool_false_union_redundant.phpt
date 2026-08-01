--TEST--
Language: bool|false union type — runtime fatal, Duplicate type false is redundant (#26555, zend_compile.c)
--FILE--
<?php
function f(bool|false $x): string {
    return 'f';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Duplicate type false is redundant in %s on line %d
