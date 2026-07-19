--TEST--
AOT: md5(null) — empty digest on 8.4 (#21181, reverts #19255)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo md5(null), "\n";
?>
--EXPECT--
d41d8cd98f00b204e9800998ecf8427e
