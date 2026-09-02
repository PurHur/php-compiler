<?php
// #36354: recursive foreach in a user function must keep the caller's FE position
// (Zend execute_data FE state). Nested/recursive calls that reuse the same operand
// slot must not end the outer iteration after the first key.
function flatten($arr, $prefix = '') {
    $out = [];
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out = array_merge($out, flatten($v, $key));
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}
$data = [
    'stats' => ['hits' => 10, 'miss' => 2],
    'tags' => ['a' => 'x', 'b' => 'y'],
    'user' => ['id' => 7, 'name' => 'bob'],
];
$flat = flatten($data);
ksort($flat);
foreach ($flat as $k => $v) {
    echo $k, '=', $v, "\n";
}
