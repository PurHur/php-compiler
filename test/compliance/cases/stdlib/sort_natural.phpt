--TEST--
stdlib sort() with SORT_NATURAL predefined constant (#3372)
--FILE--
<?php
$a = array();
$a[] = 'a10';
$a[] = 'a2';
sort($a, SORT_NATURAL);
echo implode(',', $a), "\n";

$b = array('a' => 1);
$name = 'keep';
extract($b, EXTR_SKIP);
echo isset($name) ? $name : 'skip', "\n";

$constants = get_defined_constants(true);
echo isset($constants['Core']['SORT_NATURAL']) && $constants['Core']['SORT_NATURAL'] === 6 ? "sort_ok\n" : "sort_bad\n";
echo isset($constants['Core']['EXTR_SKIP']) && $constants['Core']['EXTR_SKIP'] === 1 ? "extr_ok\n" : "extr_bad\n";
--EXPECT--
a2,a10
keep
sort_ok
extr_ok
