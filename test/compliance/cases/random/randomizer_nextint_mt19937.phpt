--TEST--
Stdlib: Random\Randomizer::nextInt() Mt19937 seeded parity (#16289, ext/random/randomizer.c)
--FILE--
<?php
$engine = new Random\Engine\Mt19937(99);
$randomizer = new Random\Randomizer($engine);
$value = $randomizer->nextInt();
echo $value === 0 ? 'fail' : 'ok';
echo ':';
echo $value;
--EXPECT--
ok:1443707200
