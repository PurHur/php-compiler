--TEST--
Language: list/array destructuring from string — NULL slots (#10486, zend_execute.c)
--FILE--
<?php
list($a, $b) = 'ab';
var_export([$a, $b]);
echo "\n";
[$x, $y] = 'xy';
var_export([$x, $y]);
echo "\n";
list($z) = $s = 'x';
var_export($z);
echo "\n";
[[ $w ]] = 'x';
var_export($w);
echo "\n";
--EXPECT--
array (
  0 => NULL,
  1 => NULL,
)
array (
  0 => NULL,
  1 => NULL,
)
NULL
NULL
