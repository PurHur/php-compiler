--TEST--
stdlib: filter_id() excess argc → ArgumentCountError (#30594)
--FILE--
<?php
try {
    filter_id('int', 'x');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_id();
    echo "NO_THROW_ZERO\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', var_export(filter_id('int'), true), "\n";
?>
--EXPECT--
ArgumentCountError: filter_id() expects exactly 1 argument, 2 given
ArgumentCountError: filter_id() expects exactly 1 argument, 0 given
ok=257
