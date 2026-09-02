<?php
// #36221 program: multi-stage array sort / filter / map pipeline
$raw = [5, 1, 8, 1, 3, 9, 2, 8, 4, 7, 6, 0, 3];
$unique = array_values(array_unique($raw));
sort($unique);
$even = array_values(array_filter($unique, static function ($x) { return $x % 2 === 0; }));
$odd = array_values(array_filter($unique, static function ($x) { return $x % 2 === 1; }));
$mapped = array_map(static function ($x) { return $x * $x; }, $even);
rsort($mapped);
$merged = array_merge($odd, $mapped);
$reduced = array_reduce($merged, static function ($a, $b) { return $a + $b; }, 0);
$slice = array_slice($merged, 0, 5);
$pad = array_pad($slice, 7, -1);
$lines = [
    'unique=' . implode(',', $unique),
    'even=' . implode(',', $even),
    'odd=' . implode(',', $odd),
    'mapped=' . implode(',', $mapped),
    'merged=' . implode(',', $merged),
    'reduced=' . $reduced,
    'pad=' . implode(',', $pad),
];
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
