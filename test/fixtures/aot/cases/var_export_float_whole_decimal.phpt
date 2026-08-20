--TEST--
AOT: thin var_export(whole float) keeps .0 so re-import stays float (#32746)
--FILE--
<?php
var_export(3.0);
echo "\n";
var_export(0.0);
echo "\n";
var_export(-0.0);
echo "\n";
var_export(10.0);
echo "\n";
var_export(1.5);
echo "\n";
var_export(INF);
echo "\n";
var_export(NAN);
echo "\n";
--EXPECT--
3.0
0.0
-0.0
10.0
1.5
INF
NAN
