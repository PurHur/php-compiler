--TEST--
stdlib array_intersect_key() key-only intersect (#4188)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
echo json_encode(array_intersect_key($a, $b)), "\n";
try {
    array_intersect_key(1, []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
{"a":1}
array_intersect_key(): Argument #1 ($array) must be of type array, int given
