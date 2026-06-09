--TEST--
stdlib array_slice() — numeric-string offset/length coercion (#4176, ext/standard/array.c)
--FILE--
<?php
echo json_encode(array_slice([1, 2, 3], '1', '1')), "\n";
try {
    array_slice([1], 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
[2]
TypeError
