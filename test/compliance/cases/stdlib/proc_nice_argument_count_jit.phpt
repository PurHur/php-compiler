--TEST--
stdlib proc_nice() JIT — ArgumentCountError on zero args (#9299, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    proc_nice();
    echo "no\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: proc_nice() expects exactly 1 argument, 0 given
