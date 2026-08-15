--TEST--
AOT array_filter null $mode under strict_types TypeError (#31360)
--FILE--
<?php
declare(strict_types=1);
// Soft falsy filterDefault AOT path segfaults on master (pre-existing); TypeError only here.
try {
    array_filter([0, 1, 2], null, null);
    echo "fail null mode\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_filter(): Argument #3 ($mode) must be of type int, null given
