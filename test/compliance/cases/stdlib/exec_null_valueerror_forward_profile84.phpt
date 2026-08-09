--TEST--
stdlib exec family null command — ValueError not TypeError on 8.4 forward profile (#18922, ext/standard/exec.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['shell_exec', 'system', 'passthru', 'exec'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_ERROR\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    } catch (TypeError $e) {
        echo "TypeError: ", $e->getMessage(), "\n";
    }
}
try {
    scandir(null);
    echo "scandir: NO_ERROR\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo "TypeError: ", $e->getMessage(), "\n";
}
try {
    popen(null, 'r');
    echo "popen: NO_ERROR\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo "TypeError: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
shell_exec(): Argument #1 ($command) must not be empty
system(): Argument #1 ($command) must not be empty
passthru(): Argument #1 ($command) must not be empty
exec(): Argument #1 ($command) must not be empty
scandir(): Argument #1 ($directory) must not be empty
popen(): Argument #1 ($command) must not be empty
