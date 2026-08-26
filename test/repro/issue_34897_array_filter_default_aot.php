<?php
// AOT: array_filter() without callback must match Zend (#34897).
var_export(array_filter([0, 1, 2]));
echo PHP_EOL;
var_export(array_filter([]));
echo PHP_EOL;
var_export(array_filter(['a' => 0, 'b' => 1, 'c' => '']));
echo PHP_EOL;
var_export(array_filter([0, 1, 2], fn ($x) => $x > 0));
echo PHP_EOL;
