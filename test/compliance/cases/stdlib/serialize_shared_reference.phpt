--TEST--
stdlib serialize() shared references emit R: markers (issue #12081, ext/standard/var.c)
--FILE--
<?php
$a = [1];
$a[1] = &$a[0];
$blob = serialize($a);
$s = 'x';
$blob2 = serialize([&$s, &$s]);
$round = unserialize($blob);
$round[0] = 9;
echo str_contains($blob, 'R:') ? "r_marker\n" : "no_r\n";
echo $blob === 'a:2:{i:0;i:1;i:1;R:2;}' ? "array_ok\n" : "array_bad\n";
echo $blob2 === 'a:2:{i:0;s:1:"x";i:1;R:2;}' ? "string_ok\n" : "string_bad\n";
echo ($round[1] === 9) ? "alias_ok\n" : "alias_bad\n";
--EXPECT--
r_marker
array_ok
string_ok
alias_ok
--CREDITS--
PurHur/php-compiler issue #12081
