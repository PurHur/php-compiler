--TEST--
Stdlib: settype($resource,'string') — Resource id #N not Error (#10691, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
var_export(settype($fp, 'string'));
echo "\n";
var_export(str_starts_with($fp, 'Resource id #'));
echo "\n";
var_export(gettype($fp));
echo "\n";
--EXPECT--
true
true
'string'
