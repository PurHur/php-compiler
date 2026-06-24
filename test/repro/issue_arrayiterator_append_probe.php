<?php
// php-src ext/spl/spl_array.c — ArrayIterator::append() (#11328).

$ai = new ArrayIterator(['a' => 1]);
$ai->append(2);
echo json_encode(iterator_to_array($ai)), "\n";
