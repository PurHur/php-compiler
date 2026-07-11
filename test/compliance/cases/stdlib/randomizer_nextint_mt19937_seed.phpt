--TEST--
Stdlib: Random\Randomizer nextInt() Mt19937 seeded parity (#16289, ext/random/randomizer.c)
--FILE--
<?php
$r = new Random\Randomizer(new Random\Engine\Mt19937(99));
echo $r->nextInt() . "\n";
echo $r->nextInt() . "\n";
$e = new Random\Engine\Mt19937(99);
echo strlen($e->generate()) . "\n";
--EXPECT--
1443707200
1551642129
4
