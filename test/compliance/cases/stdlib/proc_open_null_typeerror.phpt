--TEST--
stdlib proc_open(null) — TypeError not NULL (#18901, ext/standard/proc_open.c)
--FILE--
<?php
try {
    $pipes = [];
    proc_open(null, [], $pipes);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
proc_open(): Argument #1 ($command) must be of type array|string, null given
