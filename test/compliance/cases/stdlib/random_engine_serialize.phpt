--TEST--
stdlib Random\Engine\Mt19937 serialize round-trip preserves Randomizer output (#13296, ext/random/random.c)
--FILE--
<?php
$engine = new Random\Engine\Mt19937(42);
for ($i = 0; $i < 3; ++$i) {
    $engine->generate();
}
$restored = unserialize(serialize($engine));
$expected = (new Random\Randomizer($engine))->getInt(1, 100);
$actual = (new Random\Randomizer($restored))->getInt(1, 100);
echo $expected === $actual ? 'match' : 'mismatch', "\n";
--EXPECT--
match
