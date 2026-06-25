<?php
// Issue #11763 — hash_hkdf() numeric string $length coerces without strict_types (ext/hash/hash_hkdf.c).
$len = strlen(hash_hkdf('sha256', 'secret', '16'));
echo 'len='.$len."\n";
