--TEST--
max_memory_limit absent on PROFILE=8.4 (#23232, main/main.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'get=', var_export(ini_get('max_memory_limit'), true), "\n";
echo 'cfg=', var_export(get_cfg_var('max_memory_limit'), true), "\n";
?>
--EXPECT--
get=false
cfg=false
