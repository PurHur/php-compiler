<?php

declare(strict_types=1);

$ai = new ArrayIterator();
$ai[] = 1;
$ai[] = 2;
$ai[] = 3;
echo json_encode(iterator_to_array($ai)), "\n";
