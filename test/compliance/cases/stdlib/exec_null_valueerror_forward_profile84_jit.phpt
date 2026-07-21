--TEST--
stdlib exec family null command — ValueError not TypeError on 8.4 forward profile — JIT (#18922)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['shell_exec', 'system', 'passthru'] as $fn) {
    try {
        $fn(null);
        echo "$fn: no_exception\n";
    } catch (ValueError $e) {
        echo "$fn: ValueError\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
try {
    scandir(null);
    echo "scandir: no_exception\n";
} catch (ValueError $e) {
    echo "scandir: ValueError\n";
} catch (TypeError $e) {
    echo "scandir: TypeError\n";
}
try {
    popen(null, 'r');
    echo "popen: no_exception\n";
} catch (ValueError $e) {
    echo "popen: ValueError\n";
} catch (TypeError $e) {
    echo "popen: TypeError\n";
}
?>
--EXPECT--
shell_exec: ValueError
system: ValueError
passthru: ValueError
scandir: ValueError
popen: ValueError
