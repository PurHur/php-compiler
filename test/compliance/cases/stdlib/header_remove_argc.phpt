--TEST--
stdlib header_remove() — ArgumentCountError for extra args (#6019, ext/standard/head.c)
--FILE--
<?php
try {
    header_remove('X-Test', 'extra');
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: header_remove() expects at most 1 argument, 2 given
