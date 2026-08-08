--TEST--
Language: nested dim ??= auto-vivifies intermediates without Undefined array key (#28954, zend_execute.c)
--FILE--
<?php
$a = [];
$r = ($a['x']['y'] ??= 1);
var_export($r);
echo "\n";
var_export($a);
echo "\n";

$r2 = ($a['x']['y'] ??= 2);
var_export($r2);
echo "\n";
var_export($a);
echo "\n";

$b = [];
$r3 = ($b['x']['y']['z'] ??= 3);
var_export($r3);
echo "\n";
var_export($b);
echo "\n";

$c = ['x' => null];
$r4 = ($c['x']['y'] ??= 4);
var_export($r4);
echo "\n";
var_export($c);
echo "\n";

// Nested ?? (no assign) stays quiet and does not write.
$d = [];
$r5 = $d['x']['y'] ?? 5;
var_export($r5);
echo "\n";
var_export($d);
echo "\n";
--EXPECT--
1
array (
  'x' => array (
    'y' => 1,
  ),
)
1
array (
  'x' => array (
    'y' => 1,
  ),
)
3
array (
  'x' => array (
    'y' => array (
      'z' => 3,
    ),
  ),
)
4
array (
  'x' => array (
    'y' => 4,
  ),
)
5
array (
)
