--TEST--
stdlib realpath() on existing path and missing path
--FILE--
<?php
$ok = realpath('/tmp');
echo (strlen($ok) > 0 ? 'ok' : 'fail'), "\n";
$missing = realpath('/tmp/no-such-entry-phpc-test');
echo (strlen($missing) > 0 ? 'found' : 'missing'), "\n";
--EXPECT--
ok
missing
