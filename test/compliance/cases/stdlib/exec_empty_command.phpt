--TEST--
stdlib exec family empty command throws ValueError (ext/standard/exec.c, #12031)
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
