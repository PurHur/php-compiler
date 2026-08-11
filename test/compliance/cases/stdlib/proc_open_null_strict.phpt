--TEST--
stdlib proc_open(null) TypeError under strict_types (#30247, ext/standard/proc_open.c)
--FILE--
<?php
declare(strict_types=1);
try {
    $p = proc_open(null, [], $pipes);
    var_export($p);
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:proc_open(): Argument #1 ($command) must be of type array|string, null given
