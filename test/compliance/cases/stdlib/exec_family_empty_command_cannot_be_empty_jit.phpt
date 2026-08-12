--TEST--
stdlib exec family empty/null command ValueError — cannot be empty — JIT (#30340)
--JIT--
--FILE--
<?php
error_reporting(0);
foreach (['exec', 'system', 'passthru', 'shell_exec'] as $fn) {
    try {
        $fn('');
        echo "$fn empty: no throw\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
    try {
        $fn(null);
        echo "$fn null: no throw\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
exec(): Argument #1 ($command) cannot be empty
exec(): Argument #1 ($command) cannot be empty
system(): Argument #1 ($command) cannot be empty
system(): Argument #1 ($command) cannot be empty
passthru(): Argument #1 ($command) cannot be empty
passthru(): Argument #1 ($command) cannot be empty
shell_exec(): Argument #1 ($command) cannot be empty
shell_exec(): Argument #1 ($command) cannot be empty
