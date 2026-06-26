--TEST--
Language: true|false union type — compile fatal, use bool instead (#12045, zend_compile.c)
--FILE--
<?php
function f(true|false $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Type contains both true and false, bool should be used instead
