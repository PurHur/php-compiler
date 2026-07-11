--TEST--
stdlib hash_hkdf() — numeric string $length coerces without strict_types (#11763, ext/hash/hash_hkdf.c)
--FILE--
<?php
echo strlen(hash_hkdf('sha256', 'secret', '16')), "\n";
--EXPECT--
16
