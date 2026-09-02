<?php
// #36221 program: nested arrays, iterative flatten, array_walk
function flatten(array $a): array {
    $out = [];
    $stack = [['', $a]];
    while ($stack) {
        $frame = array_pop($stack);
        $prefix = $frame[0];
        $cur = $frame[1];
        foreach ($cur as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $stack[] = [$key, $v];
            } else {
                $out[$key] = $v;
            }
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
$sum = 0;
array_walk($flat, static function ($v, $k) use (&$sum) {
    if (is_int($v)) {
        $sum += $v;
    }
});
$lines = [];
foreach ($flat as $k => $v) {
    $lines[] = $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : $v);
}
$lines[] = 'sum=' . $sum;
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
