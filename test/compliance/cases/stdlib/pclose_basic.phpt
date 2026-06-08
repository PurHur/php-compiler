--TEST--
stdlib popen()/pclose() — pipe read and exit status (#6211, ext/standard/exec.c)
--FILE--
<?php
var_dump(function_exists('pclose'));
var_dump(function_exists('popen'));

$fp = popen('echo hello', 'r');
echo stream_get_contents($fp);
var_dump(pclose($fp));
--EXPECT--
bool(true)
bool(true)
hello
int(0)
