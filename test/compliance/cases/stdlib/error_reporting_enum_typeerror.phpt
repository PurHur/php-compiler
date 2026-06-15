--TEST--
stdlib error_reporting() — enum case error_level TypeError (#5917, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum Es: string { case B = '1'; }

try {
    error_reporting(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$prev = error_reporting(0);
echo $prev === 22527 ? "old-level\n" : "old-bad\n";
$unchanged = error_reporting(null);
echo $unchanged === 0 ? "null-unchanged\n" : "null-bad\n";
--EXPECT--
error_reporting(): Argument #1 ($error_level) must be of type ?int, Es given
old-level
null-unchanged
