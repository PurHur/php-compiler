--TEST--
filesize/filetype/filemtime/filectime/fileatime excess argc → ArgumentCountError — JIT (#30545)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['filesize', 'filetype', 'filemtime', 'filectime', 'fileatime'] as $fn) {
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
    filesize();
    echo "filesize missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
filesize() expects exactly 1 argument, 2 given
filetype() expects exactly 1 argument, 2 given
filemtime() expects exactly 1 argument, 2 given
filectime() expects exactly 1 argument, 2 given
fileatime() expects exactly 1 argument, 2 given
filesize() expects exactly 1 argument, 0 given
