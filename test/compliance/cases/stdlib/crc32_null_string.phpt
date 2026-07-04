--TEST--
stdlib crc32() null $string — empty-string checksum (#16115, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
echo crc32(null), "\n";
?>
--EXPECT--
0
