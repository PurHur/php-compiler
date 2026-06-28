--TEST--
stdlib proc_terminate() — ArgumentCountError on zero args (#13306, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);
try {
    proc_terminate();
    echo "no\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: proc_terminate() expects at least 1 argument, 0 given
