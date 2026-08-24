<?php
// AOT: serialize(SplFixedArray/ArrayObject) non-empty must match Zend (#34491).
$a = SplFixedArray::fromArray([1, 2]);
echo serialize($a), "\n";
$b = SplFixedArray::fromArray([1.5, true, false]);
echo serialize($b), "\n";
$c = new SplFixedArray(0);
echo serialize($c), "\n";
$d = new ArrayObject([1, 2]);
echo serialize($d), "\n";
$e = new ArrayObject(['x' => 1]);
echo serialize($e), "\n";
$f = new ArrayObject([]);
echo serialize($f), "\n";
