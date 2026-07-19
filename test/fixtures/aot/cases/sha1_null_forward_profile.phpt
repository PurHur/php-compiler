--TEST--
AOT: sha1(null) — empty digest on 8.4 (#21181, reverts #19255)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo sha1(null), "\n";
?>
--EXPECT--
da39a3ee5e6b4b0d3255bfef95601890afd80709
