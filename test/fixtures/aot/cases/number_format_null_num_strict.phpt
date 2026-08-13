--TEST--
AOT: number_format() null $num TypeError cites int|float under strict_types (#29976, ext/standard/basic_functions.stub.php)
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
