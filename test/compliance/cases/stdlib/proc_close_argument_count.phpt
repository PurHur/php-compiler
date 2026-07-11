--TEST--
stdlib proc_close() — ArgumentCountError on zero args (#13306, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);
try {
    proc_close();
    echo "no\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: proc_close() expects exactly 1 argument, 0 given
