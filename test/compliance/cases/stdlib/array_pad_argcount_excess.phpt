--TEST--
stdlib array_pad() rejects excess arguments (#14983, ext/standard/array.c)
--FILE--
<?php
if (defined('ARRAY_PAD_RIGHT')) {
    die('skip 4-arg array_pad enabled on PHP 8.4+ profile');
}
try {
    array_pad([1], 4, 0, STR_PAD_LEFT);
    echo "no error\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_pad() expects exactly 3 arguments, 4 given
