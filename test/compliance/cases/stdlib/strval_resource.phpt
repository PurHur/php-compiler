--TEST--
stdlib strval() / (string) on stream resource — Resource id #N (#11420, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
var_export(str_starts_with(strval($fp), 'Resource id #'));
echo "\n";
var_export(str_starts_with((string) $fp, 'Resource id #'));
echo "\n";
--EXPECT--
true
true
