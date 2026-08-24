<?php
// Repro #34498: thin AOT var_dump(array) must match Zend (no SIGABRT).
var_dump([]);
var_dump([1, 2]);
var_dump(['a' => 1, 'b' => 2]);
var_dump([1, [2, 'x' => 3]]);
