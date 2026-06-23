--TEST--
AOT: intval()/floatval() array/object/resource coercion (#10810, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

echo intval([]), "\n";
echo floatval([]), "\n";
echo @intval(new stdClass()), "\n";
$r = fopen('php://memory', 'r');
echo (@intval($r) > 0 ? 1 : 0), "\n";
?>
--EXPECT--
0
0
1
1
