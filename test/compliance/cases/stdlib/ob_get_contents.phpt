--TEST--
stdlib ob_get_contents / ob_end_clean / ob_get_length (issue #3236)
--FILE--
<?php
ob_start();
echo 'hello';
$c = ob_get_contents();
$l = ob_get_length();
ob_end_clean();
echo $c, $l, ob_get_level(), "\n";

ob_start();
echo 'outer';
ob_start();
echo 'inner';
$inner = ob_get_contents();
$innerLevel = ob_get_level();
ob_end_clean();
$outer = ob_get_contents();
$outerLevel = ob_get_level();
ob_end_clean();
echo $inner, "\n", $innerLevel, "\n", $outer, "\n", $outerLevel, "\n", ob_get_level(), "\n";
--EXPECT--
hello50
inner
2
outer
1
0
