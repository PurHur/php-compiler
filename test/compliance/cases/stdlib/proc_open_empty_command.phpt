--TEST--
stdlib proc_open() empty command array throws ValueError (ext/standard/proc_open.c, #12030)
--FILE--
<?php
declare(strict_types=1);

$pipes = [];
try {
    proc_open([], [], $pipes);
    echo "proc_open: no_exception\n";
} catch (ValueError $e) {
    echo "proc_open: ValueError\n";
}
--EXPECT--
proc_open: ValueError
