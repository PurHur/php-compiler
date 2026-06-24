<?php
// php-src ext/spl/spl_array.c — ArrayObject::append() (#11329).

$ao = new ArrayObject([1, 2]);
$ao->append(3);
echo json_encode(iterator_to_array($ao)), "\n";

$empty = new ArrayObject();
$empty->append('x');
echo json_encode(iterator_to_array($empty)), "\n";
