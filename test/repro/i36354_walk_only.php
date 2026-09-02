<?php
// #36354: recursive foreach in same function must not end outer iteration early
function walk($arr, $prefix = '') {
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            walk($v, $key);
        } else {
            echo $key, '=', $v, "\n";
        }
    }
}
$data = [
    'stats' => ['hits' => 10, 'miss' => 2],
    'tags' => ['a' => 'x', 'b' => 'y'],
    'user' => ['id' => 7, 'name' => 'bob'],
];
walk($data);
