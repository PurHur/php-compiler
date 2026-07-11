--TEST--
stdlib pow()/sqrt()/log() — numeric-string coercion + array TypeError (#4359, ext/standard/math.c)
--FILE--
<?php
var_export(pow('2', '3'));
echo "\n";
var_export(sqrt('4'));
echo "\n";
$log = log('10');
echo abs($log - 2.30258509299404568402) < 1e-10 ? "log_ok\n" : "log_bad\n";
try {
    pow([], 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    pow(new stdClass(), 1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
8
2.0
log_ok
Unsupported operand types: array ** int
Unsupported operand types: stdClass ** int
