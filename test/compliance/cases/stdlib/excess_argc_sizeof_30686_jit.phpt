--TEST--
stdlib JIT: sizeof() excess argc → ArgumentCountError cites sizeof() (#30686)
--FILE--
<?php
try {
    sizeof([1], COUNT_NORMAL, 1);
    echo "sz NO_THROW\n";
} catch (Throwable $e) {
    echo 'sz ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    sizeof();
    echo "sz0 NO_THROW\n";
} catch (Throwable $e) {
    echo 'sz0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', sizeof([1, 2]), "\n";
try {
    count([1], COUNT_NORMAL, 1);
    echo "ct NO_THROW\n";
} catch (Throwable $e) {
    echo 'ct ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
sz ArgumentCountError: sizeof() expects at most 2 arguments, 3 given
sz0 ArgumentCountError: sizeof() expects at least 1 argument, 0 given
ok=2
ct ArgumentCountError: count() expects at most 2 arguments, 3 given
