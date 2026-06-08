--TEST--
stdlib array_diff_key() key-only diff (#4188)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
echo json_encode(array_diff_key($a, $b)), "\n";
try {
    array_diff_key('not-array', []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
{"b":2}
array_diff_key(): Argument #1 ($array) must be of type array, string given
