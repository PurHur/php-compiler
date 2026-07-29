--TEST--
AOT: substr(null) — soft-null on 8.4 forward profile (#24817 / #21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo '[', substr(null, 0), ']', "\n";
--EXPECT--
[]
