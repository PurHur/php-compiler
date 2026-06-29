--TEST--
AOT: array_column() inline haystack two-arg form (#13703)
--FILE--
<?php
$r = array_column([['n' => 'a'], ['n' => 'b']], 'n');
var_export($r);
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
