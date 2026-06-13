--TEST--
AOT grapheme_stripos()/grapheme_strrpos() — compile-time fold (#6153)
--FILE--
<?php
$s = "äbcÄ";
var_export(grapheme_stripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strrpos($s, 'b'));
echo "\n";
--EXPECT--
0
1
