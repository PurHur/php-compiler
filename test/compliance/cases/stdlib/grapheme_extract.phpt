--TEST--
stdlib grapheme_extract() — grapheme cluster extraction (#6023, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_extract'), "\n";
echo (int) defined('GRAPHEME_EXTR_COUNT'), "\n";

$s = "a\xCC\x81b";
var_export(grapheme_extract($s, 1));
echo "\n";

var_export(grapheme_extract($s, 2));
echo "\n";

var_export(grapheme_extract('abc', 2, GRAPHEME_EXTR_COUNT, 1));
echo "\n";

$next = 0;
var_export(grapheme_extract('abcdef', 2, GRAPHEME_EXTR_COUNT, 0, $next));
echo "\n";
var_export($next);
echo "\n";

var_export(grapheme_extract('abc', 0));
echo "\n";

var_export(grapheme_extract('abc', 1, 99));
echo "\n";
--EXPECT--
1
1
'á'
'áb'
'bc'
'ab'
2
''
false
