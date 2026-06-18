--TEST--
language ternary assignment phi merge (issue #9159)
--FILE--
<?php
$strong = true;
$s = ($strong ? 'strong' : 'weak');
var_export($s);
echo "\n";
--EXPECT--
'strong'
