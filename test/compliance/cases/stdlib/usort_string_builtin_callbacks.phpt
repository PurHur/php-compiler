--TEST--
stdlib uksort()/usort()/uasort() string builtin callbacks (#10968, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = ['a10' => 1, 'a2' => 2];
uksort($a, 'strnatcmp');
echo implode(',', array_keys($a)), "\n";

$b = [3 => 'z', 1 => 'A', 2 => 'a'];
usort($b, 'strnatcasecmp');
echo implode(',', $b), "\n";
--EXPECT--
a2,a10
A,a,z
