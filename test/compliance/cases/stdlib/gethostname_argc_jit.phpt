--TEST--
stdlib gethostname() JIT — ArgumentCountError when extra arguments (#5981)
--FILE--
<?php
try {
    gethostname(1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError
gethostname() expects exactly 0 arguments, 1 given
