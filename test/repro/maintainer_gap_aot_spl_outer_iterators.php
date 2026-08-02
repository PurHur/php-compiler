<?php
// Maintainer gap: LimitIterator/AppendIterator/RegexIterator AOT iterator_to_array (#26825).
$it = new LimitIterator(new ArrayIterator([1, 2, 3, 4, 5]), 1, 2);
echo implode(',', iterator_to_array($it)), "\n";
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
echo implode(',', iterator_to_array($a, false)), "\n";
$r = new RegexIterator(new ArrayIterator(['foo', 'bar', 'baz']), '/^b/');
echo implode(',', iterator_to_array($r)), "\n";
