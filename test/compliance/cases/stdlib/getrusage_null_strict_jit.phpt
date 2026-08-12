--TEST--
getrusage(null) under strict_types TypeError JIT (#30361)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(getrusage(null));
    echo " uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, null given
