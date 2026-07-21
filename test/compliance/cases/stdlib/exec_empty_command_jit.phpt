--TEST--
stdlib exec family empty command throws ValueError — JIT (#12031)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['shell_exec', 'exec', 'system', 'passthru', 'popen'] as $fn) {
    try {
        if ('popen' === $fn) {
            $fn('', 'r');
        } else {
            $fn('');
        }
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
popen: ValueError
