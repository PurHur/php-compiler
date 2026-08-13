--TEST--
disk_free_space/disk_total_space excess argc → ArgumentCountError (#30552)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['disk_free_space', 'disk_total_space', 'diskfreespace'] as $fn) {
    try {
        $fn('/', 'x');
        echo "$fn excess: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
    try {
        $fn();
        echo "$fn missing: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
disk_free_space() expects exactly 1 argument, 2 given
disk_free_space() expects exactly 1 argument, 0 given
disk_total_space() expects exactly 1 argument, 2 given
disk_total_space() expects exactly 1 argument, 0 given
diskfreespace() expects exactly 1 argument, 2 given
diskfreespace() expects exactly 1 argument, 0 given
