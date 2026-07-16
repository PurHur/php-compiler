<?php
/**
 * #3722 — Random\Randomizer shuffleArray return + pickArrayKeys bitset parity.
 * Zend reference: ext/random/randomizer.c + ext/standard/array.c php_array_pick_keys
 */
$r = new Random\Randomizer(new Random\Engine\Mt19937(7));
var_export($r->pickArrayKeys(['a', 'b', 'c', 'd'], 2));
echo "\n";

$a = [1, 2, 3, 4];
$out = (new Random\Randomizer(new Random\Engine\Mt19937(1234)))->shuffleArray($a);
echo implode(',', $a), '|', implode(',', $out), "\n";
