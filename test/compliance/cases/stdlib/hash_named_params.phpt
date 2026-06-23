--TEST--
stdlib hash()/hash_hmac()/hash_pbkdf2() named parameters (#10061, #10013, ext/hash/hash.c)
--FILE--
<?php
echo hash(algo: 'md5', data: 'x'), "\n";
echo hash_hmac(algo: 'sha256', data: 'msg', key: 'k'), "\n";
echo hash_pbkdf2(algo: 'sha256', password: 'pass', salt: 'salt', iterations: 1, length: 8), "\n";
--EXPECT--
9dd4e461268c8034f5c8564e155c67a6
bf1a0c1242929b6464a6c0a9ac6298a67e09bd1cd4ef1f182ce0141691fc17a0
65acafe9
