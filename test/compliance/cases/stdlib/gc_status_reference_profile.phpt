--TEST--
stdlib gc_status() legacy schema on Zend 8.2 reference profile (#14054, ext/standard/php_gc.c)
--FILE--
<?php
$status = gc_status();
ksort($status);
echo implode(',', array_keys($status)), "\n";
echo 'runs=', array_key_exists('runs', $status) ? 'yes' : 'no', "\n";
echo 'running=', array_key_exists('running', $status) ? 'yes' : 'no', "\n";
?>
--EXPECT--
collected,roots,runs,threshold
runs=yes
running=no
