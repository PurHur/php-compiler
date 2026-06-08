--TEST--
JIT: popen()/pclose() via __compiler_popen / __compiler_pclose (#6211)
--FILE--
<?php
$fp = popen('echo hello', 'r');
echo stream_get_contents($fp);
var_dump(pclose($fp));
--EXPECT--
hello
int(0)
