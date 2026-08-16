--TEST--
stdlib JIT: doubleval() excess argc → ArgumentCountError cites doubleval() (#30688)
--FILE--
<?php
try {
    doubleval(1, 1);
    echo "dv NO_THROW\n";
} catch (Throwable $e) {
    echo 'dv ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    doubleval();
    echo "dv0 NO_THROW\n";
} catch (Throwable $e) {
    echo 'dv0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', doubleval('2.5'), "\n";
try {
    floatval(1, 1);
    echo "fv NO_THROW\n";
} catch (Throwable $e) {
    echo 'fv ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
dv ArgumentCountError: doubleval() expects exactly 1 argument, 2 given
dv0 ArgumentCountError: doubleval() expects exactly 1 argument, 0 given
ok=2.5
fv ArgumentCountError: floatval() expects exactly 1 argument, 2 given
