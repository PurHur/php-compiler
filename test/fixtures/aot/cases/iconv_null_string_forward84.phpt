--TEST--
AOT iconv() null string soft-null under 8.4 (#21197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$r = iconv('UTF-8', 'UTF-8', null);
echo ('' === $r ? 'ok' : 'bad'), "\n";
?>
--EXPECT--
ok
