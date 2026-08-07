--TEST--
AOT: mb_convert_encoding() UTF-16BE/LE (#28525)
--FILE--
<?php
echo bin2hex(mb_convert_encoding('A', 'UTF-16BE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('A', 'UTF-16LE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('あ', 'UTF-16BE', 'UTF-8')), "\n";
--EXPECT--
0041
4100
3042
