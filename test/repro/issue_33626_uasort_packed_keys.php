<?php
// Repro #33626 — AOT uasort / ArrayObject::uasort on packed lists must preserve keys.
$cmp = function ($x, $y) {
    return $x <=> $y;
};

$a = [3, 1, 2];
uasort($a, $cmp);
foreach ($a as $k => $v) {
    echo $k, ':', $v, '|';
}
echo "\n";

$o = new ArrayObject([3, 1, 2]);
$o->uasort($cmp);
foreach ($o as $k => $v) {
    echo $k, ':', $v, '|';
}
echo "\n";

$i = new ArrayIterator([3, 1, 2]);
$i->uasort($cmp);
foreach ($i as $k => $v) {
    echo $k, ':', $v, '|';
}
echo "\n";

// String-key regression guard from #33613.
$s = new ArrayObject(['b' => 2, 'a' => 1]);
$s->uasort($cmp);
foreach ($s as $k => $v) {
    echo $k, $v;
}
echo "\n";
