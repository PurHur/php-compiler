--TEST--
stdlib fopen() inline chained concat path — full path in warning (#13458, zend_operators.c)
--FILE--
<?php
@fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r');
$e = error_get_last();
echo (null !== $e && str_contains($e['message'], '/tmp/maint_99/sub/file.txt')) ? "full-path\n" : "truncated\n";

$p = '/tmp/maint_' . 99 . '/sub/file.txt';
@fopen($p, 'r');
$e2 = error_get_last();
echo (null !== $e2 && str_contains($e2['message'], '/tmp/maint_99/sub/file.txt')) ? "variable-ok\n" : "variable-bad\n";
--EXPECT--
full-path
variable-ok
