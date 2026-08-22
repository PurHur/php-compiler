<?php
// AOT: serialize(SplFixedArray) float/bool must match Zend wire (#33682).
// JIT HT export tags: bool=2, double=3 (#33520) — not VM float=2 / bool=3.
$a = SplFixedArray::fromArray([1.5, true]);
echo 'sfa=', serialize($a), "\n";
$r = unserialize(serialize($a));
echo 'rt=', json_encode(iterator_to_array($r)), "\n";

$b = [1.5, true];
echo 'arr=', serialize($b), "\n";
$rb = unserialize(serialize($b));
echo 'arr_rt=', json_encode($rb), "\n";
