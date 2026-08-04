<?php
// #27264 — hash_copy AOT property-store typing (HMAC key/flag slots).
$h = hash_init('sha256');
hash_update($h, 'ab');
$c = hash_copy($h);
hash_update($h, 'c');
hash_update($c, 'c');
echo hash_final($h) === hash_final($c) ? "ok\n" : "diff\n";
