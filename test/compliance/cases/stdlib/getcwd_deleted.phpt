--TEST--
stdlib getcwd() after cwd directory removed (#10451)
--FILE--
<?php
$path = sys_get_temp_dir().'/phpc_getcwd_deleted_compliance';
@mkdir($path);
chdir($path);
@rmdir($path);
var_export(getcwd());
echo "\n";
--EXPECT--
false
