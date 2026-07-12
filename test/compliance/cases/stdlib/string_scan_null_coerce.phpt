--TEST--
stdlib string scan builtins — null string operands coerce when non-strict (#18264, ext/standard/string.c)
--FILE--
<?php
$r = count_chars(null);
echo is_array($r) ? count($r) : 'no', "\n";
echo $r[0], "\n";
echo strspn('abc', null), "\n";
echo strcspn('abc', null), "\n";
?>
--EXPECT--
256
0
0
3
