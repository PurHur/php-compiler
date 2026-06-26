--TEST--
stdlib unserialize() R: reference marker restores aliased values (issue #12080)
--FILE--
<?php
$a = unserialize('a:2:{i:0;i:1;i:1;R:2;}');
echo is_array($a) ? '1' : '0';
echo $a[0] === $a[1] ? '1' : '0';
$a[0] = 5;
echo $a[1];
echo "\n";
--EXPECT--
115
