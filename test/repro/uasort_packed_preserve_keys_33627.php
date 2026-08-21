<?php
// AOT: uasort preserves keys on packed lists (#33627 leftover of #33613/#33620).

$cmp = function ($x, $y) {
    return $x <=> $y;
};

$packed = [3, 1, 2];
uasort($packed, $cmp);
echo 'uasort:';
foreach ($packed as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

$ao = new ArrayObject([3, 1, 2]);
$ao->uasort($cmp);
echo 'ao:';
foreach ($ao as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

$ai = new ArrayIterator([3, 1, 2]);
$ai->uasort($cmp);
echo 'ai:';
foreach ($ai as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

// String-key regression guard from #33613
$str = new ArrayObject(['b' => 2, 'a' => 1]);
$str->uasort($cmp);
echo 'str:';
foreach ($str as $k => $v) {
    echo $k, $v;
}
echo "\n";
