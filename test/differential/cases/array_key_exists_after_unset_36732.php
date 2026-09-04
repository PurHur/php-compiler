<?php
/**
 * Differential: array_key_exists after unset must match Zend (#36732).
 * DJB strHashSlots must unlink on unset — list-only unlink left peek zombies.
 */
$f = ['name' => 'p', 'handler' => 1];
unset($f['name']);
echo array_key_exists('name', $f) ? 'bad' : 'ok';
echo '|';
$a = [['name' => 'p', 'handler' => 1]];
unset($a[0]['name']);
echo array_key_exists('name', $a[0]) ? 'bad' : 'ok';
echo '|';
// Null value must still exist for array_key_exists (Zend semantics).
$n = ['k' => null];
echo array_key_exists('k', $n) ? 'ok' : 'bad';
echo "\n";
