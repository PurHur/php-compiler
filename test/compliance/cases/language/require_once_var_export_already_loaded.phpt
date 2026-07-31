--TEST--
language: var_export(require_once/include_once) after once returns true (#25852, Zend/zend_execute.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-ro-ve-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($path, "<?php return 42;\n");
require_once $path;
echo var_export(require_once $path, true), "\n";
echo var_export(include_once $path, true), "\n";
var_export(require_once $path);
echo "\n";
$a = require_once $path;
$b = require_once $path;
echo var_export($a, true), '|', var_export($b, true), "\n";
echo var_export(require $path, true), "\n";
@unlink($path);
?>
--EXPECT--
true
true
true
true|true
42
