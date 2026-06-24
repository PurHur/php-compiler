--TEST--
stdlib number_format() — null $num TypeError under strict_types (#11017, ext/standard/number_format.c)
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
number_format(): Argument #1 ($num) must be of type float, null given
