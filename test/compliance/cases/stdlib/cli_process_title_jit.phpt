--TEST--
stdlib cli_get/set_process_title JIT lowering (#8138, #5155)
--FILE--
<?php
var_export(cli_set_process_title('jit-worker'));
echo "\n";
echo cli_get_process_title(), "\n";
--EXPECT--
true
jit-worker
