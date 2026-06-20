--TEST--
stdlib linkinfo() missing path — returns -1 not false (#10294, ext/standard/link.c)
--FILE--
<?php
var_export(linkinfo('/no/such/phpc-linkinfo-missing-path'));
echo "\n";
var_export(linkinfo('/no/such/phpc-linkinfo-missing-path') === -1);
echo "\n";
--EXPECT--
PHP Warning:  linkinfo(): No such file or directory
PHP Warning:  linkinfo(): No such file or directory
-1
true
