--TEST--
stdlib hash_pbkdf2() — numeric string iterations coerces without strict_types (#11764, ext/hash/hash_pbkdf2.c)
--FILE--
<?php
echo strlen(hash_pbkdf2('sha256', 'p', 's', '1000', 20)), "\n";
--EXPECT--
20
