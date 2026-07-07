--TEST--
stdlib var_export() inline arithmetic expression + return true (#17210, lib/Compiler.php)
--FILE--
<?php
declare(strict_types=1);
echo var_export(1.0 + 0.0, true), "\n";
echo var_export(INF * 0, true), "\n";
--EXPECT--
1.0
NAN
