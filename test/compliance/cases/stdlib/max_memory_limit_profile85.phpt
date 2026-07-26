--TEST--
max_memory_limit INI present on PROFILE=8.5 with default -1 (#23232, main/main.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo 'get=', var_export(ini_get('max_memory_limit'), true), "\n";
echo 'set=', var_export(ini_set('max_memory_limit', '128M'), true), "\n";
echo 'after=', var_export(ini_get('max_memory_limit'), true), "\n";
echo 'cfg=', var_export(get_cfg_var('max_memory_limit'), true), "\n";
?>
--EXPECT--
get='-1'
set=false
after='-1'
cfg='-1'
