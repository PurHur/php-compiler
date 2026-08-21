<?php
// AOT: ArrayObject/ArrayIterator uasort/uksort reorder __spl_ht (#33613).
$cmp = function ($x, $y) {
    return $x <=> $y;
};

$a = new ArrayObject(['b' => 2, 'a' => 1]);
$ok = $a->uasort($cmp);
echo ($ok ? '1' : '0'), '|';
foreach ($a as $k => $v) {
    echo $k, $v;
}
echo "\n";

$k = new ArrayObject(['b' => 2, 'a' => 1]);
echo ($k->uksort($cmp) ? '1' : '0'), '|';
foreach ($k as $key => $v) {
    echo $key, $v;
}
echo "\n";

$i = new ArrayIterator(['b' => 2, 'a' => 1]);
echo ($i->uasort($cmp) ? '1' : '0'), '|';
foreach ($i as $key => $v) {
    echo $key, $v;
}
echo "\n";
