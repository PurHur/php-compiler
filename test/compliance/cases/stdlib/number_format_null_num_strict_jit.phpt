--TEST--
stdlib number_format() JIT — null $num TypeError cites int|float under strict_types (#29976, #11017, ext/standard/basic_functions.stub.php)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    number_format(null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
number_format(): Argument #1 ($num) must be of type int|float, null given
