<?php

// Maintainer gap / issue #10711 — serialize(ArrayObject/ArrayIterator) Zend wire + round-trip.
$ao = new ArrayObject([1 => 2, 3 => 4]);
$wire = serialize($ao);
echo str_starts_with($wire, 'O:11:"ArrayObject":4:') ? 'ao_prefix_ok' : 'ao_prefix_bad', "\n";
$ao2 = unserialize($wire);
echo json_encode($ao2->getArrayCopy()), "\n";

$ai = new ArrayIterator([1 => 2]);
$wireAi = serialize($ai);
echo str_starts_with($wireAi, 'O:13:"ArrayIterator":4:') ? 'ai_prefix_ok' : 'ai_prefix_bad', "\n";
$ai2 = unserialize($wireAi);
echo json_encode($ai2->getArrayCopy()), "\n";
