--TEST--
stdlib header() — TypeError for invalid operand types (#4514, ext/standard/head.c)
--FILE--
<?php
try {
    header(['X-Test: 1']);
} catch (TypeError $e) {
    echo 'arg1: ', $e->getMessage(), "\n";
}
try {
    header('X-Test: 1', []);
} catch (TypeError $e) {
    echo 'arg2: ', $e->getMessage(), "\n";
}
try {
    header('X-Test: 1', true, []);
} catch (TypeError $e) {
    echo 'arg3: ', $e->getMessage(), "\n";
}
--EXPECT--
arg1: header(): Argument #1 ($header) must be of type string, array given
arg2: header(): Argument #2 ($replace) must be of type bool, array given
arg3: header(): Argument #3 ($response_code) must be of type int, array given
