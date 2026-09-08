<?php
// @differential-repeat: 3 NestedJIT hash_hmac() scratch was intermittent (#36388)
// php-src: ext/hash/hash.c PHP_FUNCTION(hash_hmac)
echo hash_hmac('sha256', 'abc', 'key'), "\n";
echo hash_hmac('sha256', 'abc', 'key', true) === hex2bin(hash_hmac('sha256', 'abc', 'key')) ? "raw_ok\n" : "raw_bad\n";
