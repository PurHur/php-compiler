<?php

// Repro for #27519 — AOT array_replace NestedJIT (HashTable::replaceCopy)
$r = array_replace(['a' => 1, 'b' => 2], ['b' => 9, 'c' => 3]);
echo implode(',', array_keys($r)), '|', implode(',', array_values($r)), "\n";
