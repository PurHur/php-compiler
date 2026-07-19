--TEST--
AOT grapheme_strripos() — compile-time fold (#20810)
--FILE--
<?php
$s = "äbcÄbÄ";
var_export(grapheme_strripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strripos('hello', 'L'));
echo "\n";
--EXPECT--
5
3
