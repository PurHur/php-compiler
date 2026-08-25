<?php

// #34829 — AOT hash() non-crypto digests must match Zend (EVP leaf rejects crc32*/adler32/fnv*).
// NestedJIT HashCryptoJitHelper::hash via USER_SCRIPT_INLINE_ONLY (peer #34824).
// php-src: ext/hash/hash.c, hash_crc32.c, hash_adler32.c, hash_fnv.c

echo hash('crc32', 'abc'), "\n";
echo hash('crc32b', 'abc'), "\n";
echo hash('crc32c', 'abc'), "\n";
echo hash('adler32', 'abc'), "\n";
echo hash('fnv132', 'abc'), "\n";
echo hash('fnv1a32', 'abc'), "\n";
echo hash('md5', 'abc'), "\n";
