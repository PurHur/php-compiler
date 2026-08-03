--TEST--
preg_match bare \d single char (#27250)
--FILE--
<?php
var_export(preg_match('/\d/', 'a1'));
echo "\n";
var_export(preg_match('/\d/', 'abc'));
echo "\n";
--EXPECT--
1
0
