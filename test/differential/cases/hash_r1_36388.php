<?php
// @differential-repeat: 3 NestedJIT hash() scratch was intermittent (#36388)
// php-src: ext/hash/hash.c PHP_FUNCTION(hash)
echo hash('sha256', 'abc'), "\n";
echo hash('sha256', 'abc', true) === hex2bin(hash('sha256', 'abc')) ? "raw_ok\n" : "raw_bad\n";
