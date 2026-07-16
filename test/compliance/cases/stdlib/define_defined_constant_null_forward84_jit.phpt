--TEST--
stdlib define()/defined()/constant(null) — TypeError on 8.4 forward profile JIT (#19652, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['define', 'defined', 'constant'] as $fn) {
    try {
        if ('define' === $fn) {
            define(null, 1);
        } elseif ('defined' === $fn) {
            defined(null);
        } else {
            constant(null);
        }
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
define: define(): Argument #1 ($constant_name) must be of type string, null given
defined: defined(): Argument #1 ($constant_name) must be of type string, null given
constant: constant(): Argument #1 ($name) must be of type string, null given
