--TEST--
stdlib array_sum() / array_product() — numeric-string element coercion (#3619)
--FILE--
<?php
echo array_sum(array('1', '2', '3')), "\n";
echo array_product(array('2', '3')), "\n";
echo array_sum(array('1', '2.5')), "\n";
echo array_sum(array(1, '2.5')), "\n";
try {
    $x = array_sum(array('x'));
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
6
6
3.5
3.5
TypeError
