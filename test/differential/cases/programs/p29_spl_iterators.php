<?php
// #36221 program: ArrayIterator + ArrayObject sorting
$base = new ArrayIterator([1, 2, 3, 4, 5, 6]);
$even = [];
foreach ($base as $v) {
    if ($v % 2 === 0) {
        $even[] = $v;
    }
}
$it = new ArrayIterator(['x' => 10, 'y' => 20, 'z' => 30]);
$it->ksort();
$keys = [];
foreach ($it as $k => $v) {
    $keys[] = "$k=$v";
}
$ao = new ArrayObject([3, 1, 2]);
$ao->asort();
$aoVals = [];
foreach ($ao as $k => $v) {
    $aoVals[] = "$k:$v";
}
$out = 'even=' . implode(',', $even) . '|keys=' . implode(',', $keys) . '|ao=' . implode(',', $aoVals) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
