<?php

// #34834 — AOT hash() fnv164/fnv1a64/joaat must match Zend (re-#34828 gap).
// php-src: ext/hash/hash_fnv.c, hash_joaat.c

echo hash('fnv164', 'abc'), "\n";
echo hash('fnv1a64', 'abc'), "\n";
echo hash('joaat', 'abc'), "\n";
echo hash('crc32', 'abc'), "\n";
