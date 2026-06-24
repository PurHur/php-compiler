--TEST--
stdlib array_multisort() combined SORT_* flag bitmasks (#11238, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = ['b', 'a', 'B'];
array_multisort($a, SORT_NATURAL | SORT_FLAG_CASE);
var_export($a);
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'B',
)
