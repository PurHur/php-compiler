--TEST--
Language: true|false union type — runtime fatal, bool must be used instead (#12045, #17996, #29961, zend_compile.c)
--FILE--
<?php
function f(true|false $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Type contains both true and false, bool must be used instead in %s on line %d
