<?php
$a = iterator_to_array(new ArrayIterator(['a' => 1, 'b' => 2]), false);
echo implode(',', array_keys($a)), "\n";
var_export($a);
echo "\n";
