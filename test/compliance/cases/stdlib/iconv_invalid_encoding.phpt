--TEST--
stdlib iconv() unsupported target encoding — E_WARNING + false (#10508, ext/iconv/iconv.c)
--FILE--
<?php
$r = @iconv('UTF-8', 'INVALID//IGNORE', 'hello');
var_export($r);
echo "\n";
$r = @iconv('INVALID//IGNORE', 'UTF-8', 'hello');
var_export($r);
--EXPECT--
false
false
