--TEST--
class_implements()/Reflection SplMinHeap/SplMaxHeap Countable-first order (#25822, ext/spl/spl_heap.c)
--FILE--
<?php
echo 'SplHeap:', implode(',', class_implements('SplHeap')), "\n";
echo 'SplMinHeap:', implode(',', class_implements('SplMinHeap')), "\n";
echo 'SplMaxHeap:', implode(',', class_implements('SplMaxHeap')), "\n";
$r = new ReflectionClass('SplMinHeap');
echo 'SplMinHeap refl:', implode(',', $r->getInterfaceNames()), "\n";
$r = new ReflectionClass('SplMaxHeap');
echo 'SplMaxHeap refl:', implode(',', $r->getInterfaceNames()), "\n";
?>
--EXPECT--
SplHeap:Iterator,Traversable,Countable
SplMinHeap:Countable,Traversable,Iterator
SplMaxHeap:Countable,Traversable,Iterator
SplMinHeap refl:Countable,Traversable,Iterator
SplMaxHeap refl:Countable,Traversable,Iterator
