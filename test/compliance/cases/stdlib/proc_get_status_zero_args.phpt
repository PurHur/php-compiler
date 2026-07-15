--TEST--
stdlib proc_get_status() zero arguments — ArgumentCountError (#18508, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);

try {
    proc_get_status();
    echo "no_throw\n";
} catch (\ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
proc_get_status() expects exactly 1 argument, 0 given
