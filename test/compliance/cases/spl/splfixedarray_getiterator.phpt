--TEST--
stdlib SplFixedArray::getIterator() InternalIterator + foreach (#23168, ext/spl/spl_fixedarray.c)
--FILE--
<?php
$a = SplFixedArray::fromArray([10, 20, 30]);
echo method_exists($a, 'getIterator') ? "method=Y\n" : "method=N\n";
echo ($a instanceof IteratorAggregate) ? "IA=Y\n" : "IA=N\n";
$n = 0;
$vals = [];
foreach ($a as $k => $v) {
    ++$n;
    $vals[] = $k.':'.$v;
}
echo "foreach=$n\n";
echo implode(',', $vals), "\n";
$it = $a->getIterator();
echo get_class($it), "\n";
echo ($it instanceof InternalIterator) ? "II=Y\n" : "II=N\n";
echo ($it instanceof Iterator) ? "it_I=Y\n" : "it_I=N\n";
$n2 = 0;
$vals2 = [];
foreach ($it as $k => $v) {
    ++$n2;
    $vals2[] = $k.':'.$v;
}
echo "iter_foreach=$n2\n";
echo implode(',', $vals2), "\n";
echo implode(',', iterator_to_array($a->getIterator())), "\n";
?>
--EXPECT--
method=Y
IA=Y
foreach=3
0:10,1:20,2:30
InternalIterator
II=Y
it_I=Y
iter_foreach=3
0:10,1:20,2:30
10,20,30
