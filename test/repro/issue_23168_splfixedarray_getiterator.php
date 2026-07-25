<?php
// repro #23168 — SplFixedArray::getIterator() → InternalIterator (not ArrayIterator)
$a = SplFixedArray::fromArray([1, 2, 3]);
$it = $a->getIterator();
echo get_class($it), "\n";
echo ($it instanceof InternalIterator) ? "II=Y\n" : "II=N\n";
echo ($it instanceof Iterator) ? "I=Y\n" : "I=N\n";
$vals = [];
foreach ($it as $k => $v) {
    $vals[] = $k.':'.$v;
}
echo implode(',', $vals), "\n";
echo implode(',', iterator_to_array($a->getIterator())), "\n";
