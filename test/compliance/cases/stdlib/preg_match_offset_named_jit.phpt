--TEST--
stdlib preg_match() JIT offset: named parameter skips default flags (#16617)
--FILE--
<?php
declare(strict_types=1);

$m = [];
echo preg_match('/(a)/', 'xax', $m, offset: 1), "\n";
echo $m[1], "\n";
--EXPECT--
1
a
