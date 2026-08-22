--TEST--
stdlib array_diff array_intersect Reflection array+variadic arrays/rest (#23593, ext/standard/array.stub.php)
--FILE--
<?php
$fns = [
    'array_diff',
    'array_diff_assoc',
    'array_diff_key',
    'array_intersect',
    'array_intersect_assoc',
    'array_intersect_key',
    'array_diff_uassoc',
    'array_diff_ukey',
    'array_intersect_uassoc',
    'array_intersect_ukey',
];
foreach ($fns as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName() . ($p->isVariadic() ? '*' : '');
    }
    echo $fn, ' [', implode(',', $parts), "]\n";
}
var_export(array_diff(array: [1, 2]));
echo "\n";
try {
    array_diff(array: [1, 2], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo json_encode(array_intersect([1, 2], [2, 3])), "\n";
?>
--EXPECT--
array_diff [array,arrays*]
array_diff_assoc [array,arrays*]
array_diff_key [array,arrays*]
array_intersect [array,arrays*]
array_intersect_assoc [array,arrays*]
array_intersect_key [array,arrays*]
array_diff_uassoc [array,rest*]
array_diff_ukey [array,rest*]
array_intersect_uassoc [array,rest*]
array_intersect_ukey [array,rest*]
array (
  0 => 1,
  1 => 2,
)
ArgumentCountError: array_diff() does not accept unknown named parameters
{"1":2}
