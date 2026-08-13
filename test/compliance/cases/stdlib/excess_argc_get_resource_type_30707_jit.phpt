--TEST--
stdlib: get_resource_type() excess argc → ArgumentCountError JIT (#30707)
--FILE--
<?php
$f = fopen('php://memory', 'r');
try {
    get_resource_type($f, 'x');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', get_resource_type($f), "\n";
fclose($f);
--EXPECT--
ArgumentCountError: get_resource_type() expects exactly 1 argument, 2 given
ok=stream
