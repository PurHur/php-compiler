--TEST--
filestat path predicates excess argc → ArgumentCountError — JIT (#30544)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable', 'is_executable', 'file_exists', 'realpath'] as $fn) {
    try {
        $fn('/tmp', 1);
        echo "$fn: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    is_file();
    echo "is_file missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
is_file() expects exactly 1 argument, 2 given
is_dir() expects exactly 1 argument, 2 given
is_link() expects exactly 1 argument, 2 given
is_readable() expects exactly 1 argument, 2 given
is_writable() expects exactly 1 argument, 2 given
is_executable() expects exactly 1 argument, 2 given
file_exists() expects exactly 1 argument, 2 given
realpath() expects exactly 1 argument, 2 given
is_file() expects exactly 1 argument, 0 given
