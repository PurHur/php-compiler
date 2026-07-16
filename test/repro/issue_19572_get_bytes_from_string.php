<?php
/**
 * #19572 — Random\Randomizer::getBytesFromString (PHP 8.3+ profile).
 * Requires: PHP_COMPILER_PROFILE=8.3 or 8.4
 */
$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo bin2hex($r->getBytesFromString('abcdef', 8)), "\n";
