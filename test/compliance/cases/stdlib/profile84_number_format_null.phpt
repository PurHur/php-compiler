--TEST--
PROFILE=8.4: number_format(null) TypeError (#21379, ext/standard/number_format.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    number_format(null);
    echo "COERCE\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
number_format(): Argument #1 ($num) must be of type float, null given
