--TEST--
stdlib number_format() negative $decimals ValueError on PHP 8.4+ profile (#17369, ext/standard/number_format.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    number_format(1234.5678, -1);
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
number_format(): Argument #2 ($decimals) must be greater than or equal to 0
