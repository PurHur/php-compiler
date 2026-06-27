--TEST--
VM: preg_match()/preg_match_all() empty pattern — $matches NULL (#12688)
--FILE--
<?php
preg_match('', 'x', $m);
var_export($m);
echo "\n";
preg_match_all('', 'x', $m2);
var_export($m2);
echo "\n";
--EXPECT--
NULL
NULL
