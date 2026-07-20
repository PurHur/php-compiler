--TEST--
JIT PROFILE=8.4: number_format(null) TypeError (#21379)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
function check(): void
{
    try {
        number_format(null);
        echo "COERCE\n";
    } catch (\TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
check();
?>
--EXPECT--
number_format(): Argument #1 ($num) must be of type float, null given
