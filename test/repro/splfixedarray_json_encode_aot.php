<?php
// #33723 — AOT json_encode(SplFixedArray) must match Zend JsonSerializable toArray wire.
$s = SplFixedArray::fromArray([1.5, true, null]);
echo json_encode($s), "\n";
$s2 = SplFixedArray::fromArray([1, 2, 3]);
echo json_encode($s2), "\n";
$s3 = new SplFixedArray(3);
$s3[0] = 1;
$s3[2] = 3;
echo json_encode($s3), "\n";
$s4 = new SplFixedArray(3);
echo json_encode($s4), "\n";
