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
$r = popen(null, 'r');
echo 'popen: ', is_resource($r) ? 'resource' : 'other', "\n";
if (is_resource($r)) {
    pclose($r);
}
?>
--EXPECT--
shell_exec: ValueError
system: ValueError
passthru: ValueError
scandir: ValueError
popen: resource
