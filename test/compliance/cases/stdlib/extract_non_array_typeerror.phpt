--TEST--
stdlib extract() non-array first argument — TypeError (#11994, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    extract(1);
    echo "fail: no TypeError\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
extract(): Argument #1 ($array) must be of type array, int given
