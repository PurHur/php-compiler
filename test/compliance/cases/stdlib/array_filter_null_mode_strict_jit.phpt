--TEST--
stdlib array_filter null $mode under strict_types TypeError JIT (#31360, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
try {
    array_filter([0, 1, 2], null, null);
    echo "fail null mode\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo json_encode(array_filter([0, 1, 2], null)), "\n";
echo json_encode(array_filter([0, 1, 2])), "\n";
--EXPECT--
array_filter(): Argument #3 ($mode) must be of type int, null given
{"1":1,"2":2}
{"1":1,"2":2}
