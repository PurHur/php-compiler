--TEST--
stdlib math/base *dec strict call-site operand types (#12273–#12276, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['bindec', 'hexdec', 'octdec'] as $fn) {
    try {
        $fn(101);
        echo "$fn uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
try {
    intdiv(10, 3.0);
    echo "intdiv uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    base_convert(65.9, 10, 16);
    echo "base_convert uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
foreach (['dechex', 'decoct', 'decbin'] as $fn) {
    try {
        $fn(65.9);
        echo "$fn uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
bindec(): Argument #1 ($binary_string) must be of type string, int given
hexdec(): Argument #1 ($hex_string) must be of type string, int given
octdec(): Argument #1 ($octal_string) must be of type string, int given
intdiv(): Argument #2 ($num2) must be of type int, float given
base_convert(): Argument #1 ($num) must be of type string, float given
dechex(): Argument #1 ($num) must be of type int, float given
decoct(): Argument #1 ($num) must be of type int, float given
decbin(): Argument #1 ($num) must be of type int, float given
