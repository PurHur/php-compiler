--TEST--
stdlib exec family null command — ValueError not TypeError — JIT (ext/standard/exec.c, #18676)
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

$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'proc_open: '.(null === $result ? 'NULL' : 'other')."\n";
--EXPECT--
shell_exec: ValueError
system: ValueError
passthru: ValueError
proc_open: NULL
