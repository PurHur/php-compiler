<?php

declare(strict_types=1);

// #34824 — AOT crc32() must match Zend (NestedJIT Crc32JitHelper, no private u32()).
// php-src: ext/standard/crc32.c PHP_FUNCTION(crc32)

echo crc32('abc'), "\n";
echo crc32('foo'), "\n";
echo crc32('test'), "\n";
echo crc32(''), "\n";
echo crc32('The quick brown fox jumped over the lazy dog.'), "\n";
