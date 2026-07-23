--TEST--
iterator_to_array(new ArrayIterator/ArrayObject([...]), false) reindexes string keys (#22702, ext/spl/iterator.c)
--FILE--
<?php
$ai = iterator_to_array(new ArrayIterator(['a' => 1, 'b' => 2]), false);
echo implode(',', array_keys($ai)), "\n";
echo json_encode($ai), "\n";

$ao = iterator_to_array(new ArrayObject(['x' => 3, 'y' => 4]), false);
echo implode(',', array_keys($ao)), "\n";
echo json_encode($ao), "\n";

$keep = iterator_to_array(new ArrayIterator(['a' => 1, 'b' => 2]), true);
echo implode(',', array_keys($keep)), "\n";
--EXPECT--
0,1
[1,2]
0,1
[3,4]
a,b
