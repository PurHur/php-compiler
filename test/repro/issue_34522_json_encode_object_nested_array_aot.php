<?php
// #34522 — FORCE_OBJECT must shape the object container only, not nested array values.
$o = (object) ['a' => 1, 'b' => [2]];
echo json_encode($o), "\n";
$ao = new ArrayObject(['x' => [1, 2]]);
echo json_encode($ao), "\n";
