--TEST--
stdlib realpath() resolves relative paths to absolute canonical paths (#4555)
--FILE--
<?php
$dot = realpath('.');
echo (is_string($dot) && strlen($dot) > 1 && $dot[0] === '/') ? "absolute\n" : "relative\n";
$missing = realpath('/tmp/no-such-entry-phpc-realpath-canonical');
echo ($missing === false) ? "missing\n" : "found\n";
echo realpath('') === false ? "empty\n" : "not-empty\n";
--EXPECT--
absolute
missing
not-empty
