--TEST--
stdlib parse_str() — uninitialized by-ref $result (#7111, re-#6986)
--FILE--
<?php
declare(strict_types=1);
parse_str('a=1&b=2', $out);
var_export($out);
echo "\n";

$prefilled = ['keep' => 'x'];
parse_str('new=1', $prefilled);
echo isset($prefilled['keep']) ? 'had-existing' : 'no-existing', ' ', $prefilled['new'], "\n";
--EXPECT--
array (
  'a' => '1',
  'b' => '2',
)
no-existing 1
