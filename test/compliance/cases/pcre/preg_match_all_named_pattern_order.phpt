--TEST--
preg_match_all() named captures under PREG_PATTERN_ORDER (#22835)
--FILE--
<?php
declare(strict_types=1);

preg_match_all('/(?<n>\d+)/', 'a12b34', $m);
var_export(isset($m['n']));
echo "\n";
var_export($m['n']);
echo "\n";
var_export($m[1]);
echo "\n";

preg_match_all('/(?<n>\d+)/', 'a12b34', $set, PREG_SET_ORDER);
var_export(isset($set[0]['n']) && $set[0]['n'] === '12');
echo "\n";
--EXPECT--
true
array (
  0 => '12',
  1 => '34',
)
array (
  0 => '12',
  1 => '34',
)
true
