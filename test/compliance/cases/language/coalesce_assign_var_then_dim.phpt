--TEST--
Language: var ??= then dim ??= writes live CV (not stale expression snapshot) (#29145, zend_execute.c)
--FILE--
<?php
$a ??= [];
$a["x"] ??= 1;
var_export($a);
echo "\n";

$b = null;
$b ??= [];
$b["x"] ??= 1;
var_export($b);
echo "\n";

unset($c);
$c ??= ["x" => 0];
$c["x"] ??= 1;
$c["y"] ??= 2;
var_export($c);
echo "\n";

// Echo between statements (forces refresh path — still green).
$d ??= [];
echo "between\n";
$d["x"] ??= 1;
var_export($d);
echo "\n";

// Control: plain assign then dim ??=
$e = [];
$e["x"] ??= 1;
var_export($e);
echo "\n";
--EXPECT--
array (
  'x' => 1,
)
array (
  'x' => 1,
)
array (
  'x' => 0,
  'y' => 2,
)
between
array (
  'x' => 1,
)
array (
  'x' => 1,
)
