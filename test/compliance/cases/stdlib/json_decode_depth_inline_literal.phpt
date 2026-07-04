--TEST--
stdlib json_decode() inline literal depth limit — assoc ConstFetch not mis-bound (#9489)
--FILE--
<?php
var_export(json_decode('{"a":{"b":1}}', true, 1));
echo " depth-err=", json_last_error(), "\n";
?>
--EXPECT--
NULL depth-err=1
