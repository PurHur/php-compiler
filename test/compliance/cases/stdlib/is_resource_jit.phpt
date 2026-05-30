--TEST--
JIT: is_resource() via __compiler_is_resource (#3519)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
echo is_resource($f) ? 'open' : 'closed', "\n";
echo is_resource(null) ? 'open' : 'closed', "\n";
echo is_resource(1) ? 'open' : 'closed', "\n";
fclose($f);
echo is_resource($f) ? 'open' : 'closed', "\n";
--EXPECT--
open
closed
closed
closed
