--TEST--
Stdlib: get_class() rejects 2nd arg under PROFILE=8.4 — no phantom allow_string (#28310 / was #17395)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo get_class(new stdClass(), false), "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo get_class('stdClass', true), "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_class() expects at most 1 argument, 2 given
get_class() expects at most 1 argument, 2 given
