--TEST--
stdlib str_replace()/str_ireplace() named search:/replace:/subject: arguments (#9648, ext/standard/string.c)
--FILE--
<?php
var_export(str_replace(search: 'a', replace: 'b', subject: 'aca'));
echo "\n";
var_export(str_ireplace(search: 'A', replace: 'b', subject: 'AcA'));
echo "\n";
--EXPECT--
'bcb'
'bcb'
