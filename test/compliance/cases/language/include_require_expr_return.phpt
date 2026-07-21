--TEST--
language: include/require(+_once) expression returns 1 or file return (#21938, Zend/zend_execute.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc-inc-ret-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);
$empty = $dir . '/empty.php';
$ret7 = $dir . '/ret7.php';
file_put_contents($empty, "<?php\n");
file_put_contents($ret7, "<?php return 7;\n");

echo 'empty_require_once=';
var_export(require_once $empty);
echo "\n";
echo 'empty_include_once=';
var_export(include_once $empty);
echo "\n";
echo 'empty_include=';
var_export(include $empty);
echo "\n";
echo 'empty_require=';
var_export(require $empty);
echo "\n";
echo 'ret7_require=';
var_export(require $ret7);
echo "\n";
echo 'ret7_assign=';
$v = require $ret7;
var_export($v);
echo "\n";
?>
--EXPECT--
empty_require_once=1
empty_include_once=true
empty_include=1
empty_require=1
ret7_require=7
ret7_assign=7
