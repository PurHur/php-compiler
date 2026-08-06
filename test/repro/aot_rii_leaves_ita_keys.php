<?php
// Repro #27257 — AOT RecursiveIteratorIterator LEAVES_ONLY + iterator_to_array key overwrite.
$it = new RecursiveArrayIterator([1, [2, 3]]);
$flat = iterator_to_array(new RecursiveIteratorIterator($it));
echo implode(',', $flat), "\n";
