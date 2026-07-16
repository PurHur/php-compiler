--TEST--
Stdlib: Random\Randomizer pickArrayKeys() seeded Mt19937 parity (#3722, ext/random/randomizer.c)
--FILE--
<?php
$r = new Random\Randomizer(new Random\Engine\Mt19937(7));
$keys = $r->pickArrayKeys(['a', 'b', 'c', 'd'], 2);
var_export($keys);
echo "\n";
$assoc = $r->pickArrayKeys(['x' => 1, 'y' => 2, 'z' => 3], 2);
var_export($assoc);
echo "\n";
$one = $r->pickArrayKeys(['p' => 1, 'q' => 2], 1);
var_export($one);
echo "\n";
--EXPECT--
array (
  0 => 0,
  1 => 3,
)
array (
  0 => 'x',
  1 => 'z',
)
array (
  0 => 'p',
)
