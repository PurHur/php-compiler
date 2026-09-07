<?php
// @differential-repeat: 3 NestedJIT md5 scratch was intermittent (#36388)
// php-src: ext/standard/md5.c PHP_FUNCTION(md5)
echo md5('abc'), "\n";
echo md5('abc', true) === hex2bin(md5('abc')) ? "raw_ok\n" : "raw_bad\n";
