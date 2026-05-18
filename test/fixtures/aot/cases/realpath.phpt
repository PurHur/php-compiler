--TEST--
AOT: realpath() via libc
--FILE--
<?php
$ok = realpath('/tmp');
echo (strlen($ok) > 0 ? 'ok' : 'fail'), "\n";
$missing = realpath('/tmp/no-such-entry-phpc-aot');
echo (strlen($missing) > 0 ? 'found' : 'missing'), "\n";
--EXPECT--
ok
missing
