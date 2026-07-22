<?php
// Repro #22331 — HashContext serialize round-trip
$c = hash_init('sha256');
hash_update($c, 'ab');
$c2 = unserialize(serialize($c));
hash_update($c2, 'cd');
echo hash_final($c2), "\n";
echo hash('sha256', 'abcd'), "\n";
