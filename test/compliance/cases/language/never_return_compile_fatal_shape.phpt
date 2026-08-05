--TEST--
Language: never-return compile fatal emits Zend-shaped "Fatal error:" not "parseAndCompile failure:" (#27718)
--FILE--
<?php
function f(): never {
    return 1;
}
--EXPECTF--
Fatal error: A never-returning function must not return in %s on line %d
