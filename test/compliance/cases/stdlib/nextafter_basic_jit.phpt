--TEST--
stdlib nextafter() JIT/AOT — IEEE next representable float (#9241)
--FILE--
<?php
declare(strict_types=1);

var_export(nextafter(1.0, 2.0));
echo "\n";
var_export(nextafter(1.0, 0.0));
echo "\n";
--EXPECT--
1.0000000000000002
0.9999999999999999
