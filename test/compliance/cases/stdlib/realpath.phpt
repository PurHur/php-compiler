--TEST--
stdlib realpath() on existing path
--FILE--
<?php
$rp = realpath('.');
echo is_string($rp) ? 'y' : 'n', "\n";
echo realpath('/no/such/path-xyz-missing') === false ? 'y' : 'n', "\n";
--EXPECT--
y
y
