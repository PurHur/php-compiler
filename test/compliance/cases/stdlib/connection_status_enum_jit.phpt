--TEST--
stdlib connection_status() JIT int; ConnectionStatus phantom (#28931, re-#7234)
--FILE--
<?php
echo enum_exists('ConnectionStatus', false) ? 'enum' : 'noenum', "\n";
$st = connection_status();
echo $st, "\n";
echo $st === CONNECTION_NORMAL ? "match\n" : "bad\n";
--EXPECT--
noenum
0
match
