--TEST--
stdlib exec family empty command throws ValueError — JIT (#12031)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['shell_exec', 'exec', 'system', 'passthru'] as $fn) {
    try {
        $fn('');
        echo "$fn: no_exception\n";
    } catch (ValueError $e) {
        echo "$fn: ValueError\n";
    }
}
--EXPECT--
shell_exec: ValueError
exec: ValueError
system: ValueError
passthru: ValueError
