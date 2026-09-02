<?php
// #36221 program: references — swap, by-ref foreach mutate, array aliasing
function swap(&$a, &$b): void {
    $t = $a;
    $a = $b;
    $b = $t;
}
$x = 1;
$y = 2;
swap($x, $y);
$arr = ['a' => 10, 'b' => 20, 'c' => 30];
foreach ($arr as &$v) {
    $v *= 2;
}
unset($v);
$alias =& $arr['b'];
$alias += 5;
$nested = [1, 2, 3];
$ref =& $nested[1];
$ref = 99;
$out = "xy=$x,$y\narr=" . json_encode($arr) . "\nnested=" . json_encode($nested) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
