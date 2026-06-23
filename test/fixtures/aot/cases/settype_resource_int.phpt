--TEST--
AOT settype($resource,'int') — resource id preserved (#10812, ext/standard/type.c)
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
?>
--EXPECT--
true
true
