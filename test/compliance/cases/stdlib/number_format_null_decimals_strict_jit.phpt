--TEST--
stdlib number_format() JIT — null $decimals TypeError under strict_types (#29764, ext/standard/number_format.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    number_format(1.5, null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
number_format(): Argument #2 ($decimals) must be of type int, null given
