<?php
// AOT: natsort/natcasesort preserve keys on packed lists (#33618).
// #33606 used string keys where key-order == value-order — that fixture stays green either way.

$packed = ['a10', 'a2'];
natsort($packed);
echo 'natsort:';
foreach ($packed as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

$case = ['A10', 'a2'];
natcasesort($case);
echo 'natcasesort:';
foreach ($case as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

$str = ['x' => 'a10', 'y' => 'a2'];
natsort($str);
echo 'strkeys:';
foreach ($str as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";

$ao = new ArrayObject(['a10', 'a2']);
$ao->natsort();
echo 'ao:';
foreach ($ao as $k => $v) {
    echo $k, ':', $v, ';';
}
echo "\n";
