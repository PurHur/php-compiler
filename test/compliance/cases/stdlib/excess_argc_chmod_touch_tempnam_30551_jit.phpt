--TEST--
chmod/touch/tempnam excess argc → ArgumentCountError — JIT (#30551)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    chmod('/tmp', 0777, 'x');
    echo "chmod excess: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    chmod('/tmp');
    echo "chmod missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    touch('/tmp/t', time(), time(), 'x');
    echo "touch excess: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    touch();
    echo "touch missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    tempnam('/tmp', 'p', 'x');
    echo "tempnam excess: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    tempnam('/tmp');
    echo "tempnam missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
chmod() expects exactly 2 arguments, 3 given
chmod() expects exactly 2 arguments, 1 given
touch() expects at most 3 arguments, 4 given
touch() expects at least 1 argument, 0 given
tempnam() expects exactly 2 arguments, 3 given
tempnam() expects exactly 2 arguments, 1 given
