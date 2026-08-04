<?php
// Repro #27241 — hash_pbkdf2 $length is hex char count when binary=false (ext/hash/hash.c)
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 8), "\n";
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 16), "\n";
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 7), "\n";
echo bin2hex(hash_pbkdf2('sha256', 'pass', 'salt', 1000, 8, true)), "\n";
