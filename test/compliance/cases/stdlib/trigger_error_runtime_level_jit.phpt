--TEST--
Stdlib: trigger_error() runtime message and error_level (JIT, #4443)
--FILE--
<?php
$arr = [1024];
$msg = 'dynamic';
echo trigger_error($msg, $arr[0]) ? '1' : '0';
echo "\nok\n";
--EXPECTF--
PHP Notice:  dynamic
1
ok
