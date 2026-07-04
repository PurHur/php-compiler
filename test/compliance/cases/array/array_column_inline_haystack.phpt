--TEST--
stdlib array_column() inline array-of-array haystack literal (#13703, #15960)
--FILE--
<?php
declare(strict_types=1);
$r = array_column([['n' => 'a'], ['n' => 'b']], 'n');
var_export($r);
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
