--TEST--
Stdlib: settype($resource,'int') — resource id not legacy 1 (#10812, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

$r = fopen('php://memory', 'r');
$expected = (int) $r;
@settype($r, 'int');
var_export($r === $expected);
echo "\n";
var_export(is_int($r));
echo "\n";
var_export($r > 0);
echo "\n";
var_export(gettype($r));
echo "\n";
?>
--EXPECT--
true
true
true
'integer'
