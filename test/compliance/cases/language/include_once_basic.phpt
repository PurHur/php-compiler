--TEST--
language: include_once/include return value + once bookkeeping (Zend/zend_execute.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc-include-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);
$a = $dir . '/a.php';

file_put_contents($a, "<?php\\necho \\\"A\\\\n\\\";\\nreturn 123;\\n");

include_once $a;
include_once $a;
$rv = include $a;
var_dump($rv);
?>
--EXPECT--
A
A
int(123)

