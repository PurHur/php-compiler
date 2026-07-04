--TEST--
stdlib md5()/sha1() null $string — empty-string digest (#16114, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
echo md5(null), "\n";
echo sha1(null), "\n";
?>
--EXPECT--
d41d8cd98f00b204e9800998ecf8427e
da39a3ee5e6b4b0d3255bfef95601890afd80709
