--TEST--
stdlib in_array()/array_search() strict JIT — object identity in haystack (#4261)
--JIT--
--FILE--
<?php
declare(strict_types=1);
$o = new stdClass;
$list = [$o, new stdClass];
echo in_array($o, $list, true) ? 'y' : 'n', "\n";
echo array_search($o, $list, true) === 0 ? 'y' : 'n', "\n";
$nested = [[1], [1]];
echo in_array([1], $nested, true) ? 'y' : 'n', "\n";
echo in_array([2], $nested, true) ? 'y' : 'n', "\n";
--EXPECT--
y
y
y
n
