--TEST--
Stdlib: Random\Randomizer getInt() seeded Mt19937 parity (#13191)
--FILE--
<?php
$methods = get_class_methods(Random\Randomizer::class);
var_export(count($methods));
echo "\n";
$engine = new Random\Engine\Mt19937(1234);
$randomizer = new Random\Randomizer($engine);
var_export($randomizer->getInt(1, 100));
echo "\n";
--EXPECT--
9
76
