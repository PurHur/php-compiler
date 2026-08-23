<?php
// #34293 — thin standalone AOT hash_copy must not call NestedJIT (SEGV).
$h = hash_init('sha256');
hash_update($h, 'ab');
$c = hash_copy($h);
hash_update($h, 'c');
hash_update($c, 'd');
echo hash_final($h), "\n", hash_final($c), "\n";
