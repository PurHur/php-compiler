<?php
// #36354 / #36221: recursive flatten stops after first top-level key on VM
function flatten(array $a, string $prefix = ''): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            foreach (flatten($v, $key) as $fk => $fv) {
                $out[$fk] = $fv;
            }
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}
$tree = [
    'user' => ['id' => 7, 'name' => 'ada'],
    'tags' => ['php', 'aot'],
    'stats' => ['a' => ['hits' => 3], 'b' => ['hits' => 1]],
];
$flat = flatten($tree);
ksort($flat);
foreach ($flat as $k => $v) {
    echo $k, '=', $v, "\n";
}
