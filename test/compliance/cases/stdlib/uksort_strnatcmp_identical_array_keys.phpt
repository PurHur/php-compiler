--TEST--
stdlib uksort() strnatcmp + strict array_keys() identity — compile arg wiring (#16056, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = ['a10' => 1, 'a2' => 2];
uksort($a, 'strnatcmp');
echo ['a2', 'a10'] === array_keys($a) ? "ok\n" : "fail\n";
$c = ['x' => 1, 'y' => 2, 'z' => 1];
uasort($c, 'strcmp');
echo ['x', 'z', 'y'] === array_keys($c) ? "ok\n" : "fail\n";
--EXPECT--
ok
ok
