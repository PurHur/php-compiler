--TEST--
stdlib get_parent_class() — ArgumentCountError for extra args (#5126, ext/standard/class.c)
--FILE--
<?php
try {
    get_parent_class('stdClass', true);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: get_parent_class() expects at most 1 argument, 2 given
