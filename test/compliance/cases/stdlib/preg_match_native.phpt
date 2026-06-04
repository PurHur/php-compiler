--TEST--
stdlib preg_match() native VmPreg path (issue #4874)
--FILE--
<?php
var_export(preg_match('/^a+$/', 'aaa', $m));
echo "\n";
var_export($m[0]);
--EXPECT--
1
'aaa'
