<?php
// Issue #11764 — hash_pbkdf2() numeric string iterations coerces without strict_types (ext/hash/hash_pbkdf2.c).
$len = strlen(hash_pbkdf2('sha256', 'p', 's', '1000', 20));
echo 'len='.$len."\n";
