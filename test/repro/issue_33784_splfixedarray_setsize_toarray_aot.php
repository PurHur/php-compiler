<?php
// #33784 — SplFixedArray::setSize / toArray thin-AOT
$a = new SplFixedArray(2);
$a[0] = 10;
$a->setSize(4);
echo $a->getSize(), '|';
var_export(isset($a[2]));
echo '|';
$a[3] = 99;
echo $a[3], '|';
$a->setSize(1);
echo $a->getSize(), '|';
$b = SplFixedArray::fromArray([1, 2, 3]);
echo json_encode($b->toArray());
