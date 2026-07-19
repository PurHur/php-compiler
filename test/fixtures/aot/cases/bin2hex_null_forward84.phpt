--TEST--
AOT: bin2hex(null) — empty string on 8.4 (#21181, reverts #20154)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo bin2hex(null) === '' ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
