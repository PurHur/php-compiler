<?php
// Issue #24730: array_map after by-ref array_walk / sort must not bind prior EXEC_RETURN.
$arr = [1, 2, 3, 4];
array_walk($arr, function (&$value, $key) {
    $value = $value * 2;
});
if ($arr !== [2, 4, 6, 8]) {
    fwrite(STDERR, "array_walk alone failed\n");
    exit(1);
}

$assoc = ['a' => 1, 'b' => 2, 'c' => 3];
$result = array_map(fn ($v) => $v * 10, $assoc);
if ($result !== ['a' => 10, 'b' => 20, 'c' => 30]) {
    fwrite(STDERR, 'array_map after array_walk failed: ' . var_export($result, true) . "\n");
    exit(1);
}

$a = [1, 2];
sort($a);
$b = [10, 20];
$mapped = array_map(fn ($x) => $x + 1, $b);
if ($mapped !== [11, 21]) {
    fwrite(STDERR, 'array_map after sort failed: ' . var_export($mapped, true) . "\n");
    exit(1);
}

echo "ok\n";
