--TEST--
Stdlib: Random\Randomizer shuffleArray() returns shuffled copy (#3722, ext/random/randomizer.c)
--FILE--
<?php
$a = [1, 2, 3, 4];
$r = new Random\Randomizer(new Random\Engine\Mt19937(1234));
$out = $r->shuffleArray($a);
echo implode(',', $a) . "\n";
echo implode(',', $out) . "\n";
echo count($out) . "\n";
--EXPECT--
1,2,3,4
2,3,1,4
4
