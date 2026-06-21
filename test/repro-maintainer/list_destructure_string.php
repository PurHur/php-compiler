<?php
// Repro for #10486 — list/array destructuring from string yields NULL slots (Zend/zend_execute.c).
list($a, $b) = 'ab';
var_export([$a, $b]);
echo "\n";
