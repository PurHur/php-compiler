--TEST--
var_export/var_dump/print_r preserve IEEE754 negative zero sign (#23746)
--FILE--
<?php
$z = -0.0;
echo var_export($z, true), "\n";
var_dump($z);
print_r($z); echo "\n";
echo "---positive---\n";
$p = 0.0;
echo var_export($p, true), "\n";
var_dump($p);
print_r($p); echo "\n";
--EXPECT--
-0.0
float(-0)
-0
---positive---
0.0
float(0)
0
