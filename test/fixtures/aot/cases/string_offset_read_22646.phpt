--TEST--
AOT: string offset $s[$i] positive / negative / OOR (#22646)
--FILE--
<?php
$s = 'AOT';
echo $s[0], $s[1], $s[2], "\n";
echo $s[-1], $s[-2], $s[-3], "\n";
echo strlen($s[99]), "\n";
echo strlen($s[-99]), "\n";
?>
--EXPECTF--
AOT
TOA
%sUninitialized string offset%s
0
%sUninitialized string offset%s
0
