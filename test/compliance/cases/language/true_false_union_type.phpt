--TEST--
Language: true|false union type — runtime fatal, use bool instead (#12045, #17996, zend_compile.c)
--FILE--
<?php
function f(true|false $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Type contains both true and false, bool should be used instead in %s on line %d
