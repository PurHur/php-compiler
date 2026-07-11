--TEST--
Stdlib: Random\Randomizer shuffleArray() in-place seeded parity (#16290, ext/random/randomizer.c)
--FILE--
<?php
$a = [1, 2, 3, 4];
$r = new Random\Randomizer(new Random\Engine\Mt19937(1234));
$r->shuffleArray($a);
echo implode(',', $a) . "\n";
echo count($a) . "\n";
--EXPECT--
2,3,1,4
4
