--TEST--
JIT: scandir() optional context + excess argc (#30569)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = scandir('.', SCANDIR_SORT_ASCENDING, null);
    echo 'ok is_array=', is_array($r) ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    scandir('.', SCANDIR_SORT_ASCENDING, 1);
    echo "CTX_NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    scandir('.', SCANDIR_SORT_ASCENDING, null, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
ok is_array=1
scandir(): Argument #3 ($context) must be of type resource or null, int given
scandir() expects at most 3 arguments, 4 given
