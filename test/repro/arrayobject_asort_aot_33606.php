<?php
// AOT: ArrayObject::asort/ksort/natsort/natcasesort reorder __spl_ht (#33606).
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$ok = $a->asort();
echo ($ok ? '1' : '0'), '|';
foreach ($a as $k => $v) {
    echo $k, $v;
}
echo "\n";

$k = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
echo ($k->ksort() ? '1' : '0'), '|';
foreach ($k as $key => $v) {
    echo $key, $v;
}
echo "\n";

$n = new ArrayObject(['f10' => 'img10', 'f2' => 'img2', 'f1' => 'img1']);
echo ($n->natsort() ? '1' : '0'), '|';
foreach ($n as $key => $v) {
    echo $key, ':', $v, ';';
}
echo "\n";

$i = new ArrayIterator(['b' => 2, 'a' => 1]);
echo ($i->asort() ? '1' : '0'), '|';
foreach ($i as $key => $v) {
    echo $key, $v;
}
echo "\n";
