--TEST--
AOT: realpath() via libc
--FILE--
<?php
$ok = realpath('/tmp');
$missing = realpath('/tmp/no-such-entry-phpc-aot');
echo strlen($ok), "\n";
echo strlen($missing), "\n";
--EXPECTF--
%d
0
