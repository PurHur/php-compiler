<?php
// Repro for #10486 — list/array destructuring from string leaves NULL slots (Zend parity).
list($a, $b) = 'ab';
var_export([$a, $b]);
echo "\n";
