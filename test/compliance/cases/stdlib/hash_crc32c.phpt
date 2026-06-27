--TEST--
stdlib hash('crc32c') Castagnoli digest (issue #12839, ext/hash/hash_crc32c.c)
--FILE--
<?php
declare(strict_types=1);

echo in_array('crc32c', hash_algos(), true) ? 'listed' : 'missing', "\n";
echo hash('crc32c', 'test'), "\n";
echo hash('crc32c', ''), "\n";
echo hash('crc32c', 'abc'), "\n";
?>
--EXPECT--
listed
86a072c0
00000000
364b3fb7
