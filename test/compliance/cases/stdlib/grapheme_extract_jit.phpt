--TEST--
stdlib grapheme_extract() JIT — compile-time fold (#6023, #19965)
--FILE--
<?php
$s = "a\xCC\x81b";
var_export(grapheme_extract($s, 1));
echo "\n";

var_export(grapheme_extract($s, 2));
echo "\n";

var_export(grapheme_extract('abc', 2, GRAPHEME_EXTR_COUNT, 1));
echo "\n";

var_export(grapheme_extract('abc', 0));
echo "\n";

var_export(grapheme_extract('abc', 1, 99));
echo "\n";
--EXPECT--
'á'
'áb'
'bc'
''
false
