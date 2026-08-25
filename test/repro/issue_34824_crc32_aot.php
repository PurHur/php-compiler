<?php

// #34824 — AOT crc32() must match Zend.
// NestedJIT corrupted `update(..., byteOrd($data[$i]), ...)` nested call args; bind ordinal
// to a local. USER_SCRIPT_INLINE_ONLY skips the stale prelinked unit.o until refreshed.
// php-src: ext/standard/crc32.c PHP_FUNCTION(crc32)

echo crc32('abc'), "\n";
echo crc32('foo'), "\n";
echo crc32('test'), "\n";
echo crc32(''), "\n";
