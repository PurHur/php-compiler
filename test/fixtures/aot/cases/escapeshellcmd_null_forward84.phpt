--TEST--
AOT: escapeshellcmd null — soft-null on 8.4 forward profile (#21221, re-#19333)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo escapeshellcmd(null) === '' ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
