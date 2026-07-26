--TEST--
hash_hkdf algo/key/length/info/salt named args (JIT, issue #23290)
--FILE--
<?php
echo bin2hex(hash_hkdf(algo: 'sha256', key: 'ikm', length: 8, info: 'i', salt: 's')), PHP_EOL;
--EXPECT--
b069c08f611a5338
