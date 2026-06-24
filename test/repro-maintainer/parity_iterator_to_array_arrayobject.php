<?php
// php-src ext/spl/iterator.c — iterator_to_array(ArrayObject) (#11228).
$ao = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]));
ksort($ao);
echo json_encode($ao), "\n";

$ai = iterator_to_array(new ArrayIterator(['x' => 3, 'y' => 4]));
ksort($ai);
echo json_encode($ai), "\n";

$packed = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]), false);
echo json_encode(array_values($packed)), "\n";
