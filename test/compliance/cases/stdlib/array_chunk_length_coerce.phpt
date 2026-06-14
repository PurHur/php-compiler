--TEST--
stdlib array_chunk() — numeric-string and float length coercion (#4191, ext/standard/array.c)
--FILE--
<?php
echo json_encode(array_chunk([1, 2, 3, 4, 5], '2')), "\n";
echo json_encode(array_chunk([1, 2, 3], 2.9)), "\n";
try {
    array_chunk([1, 2, 3], 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
try {
    array_chunk([1, 2, 3], []);
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
[[1,2],[3,4],[5]]
[[1,2],[3]]
TypeError
TypeError
