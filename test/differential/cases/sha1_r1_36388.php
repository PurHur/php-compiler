<?php
// @differential-repeat: 3 NestedJIT sha1 scratch was intermittent (#36388)
// php-src: ext/standard/sha1.c PHP_FUNCTION(sha1)
echo sha1('abc'), "\n";
echo sha1('abc', true) === hex2bin(sha1('abc')) ? "raw_ok\n" : "raw_bad\n";
