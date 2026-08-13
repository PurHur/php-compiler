--TEST--
language: define() excess argc → ArgumentCountError JIT (#30573, Zend/zend_builtin_functions.c)
--FILE--
<?php
try {
    var_export(define('ZZZ_DEF4', 1, false, 'extra'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo defined('ZZZ_DEF4') ? "defined\n" : "undef\n";
try {
    var_export(define('ZZZ_DEF3', 2, false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo defined('ZZZ_DEF3') ? "defined3\n" : "undef3\n";
--EXPECT--
ArgumentCountError: define() expects at most 3 arguments, 4 given
undef
true
defined3
