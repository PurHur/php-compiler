--TEST--
AOT bcadd(null) TypeError under strict_types (#29977, ext/bcmath)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
try {
    echo bcadd(null, '1');
    echo "bad\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bcadd(): Argument #1 ($num1) must be of type string, null given
