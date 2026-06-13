--TEST--
stdlib grapheme_strlen() — grapheme cluster count (#5914, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_strlen'), "\n";
echo grapheme_strlen('café'), "\n";
echo grapheme_strlen(''), "\n";
var_dump(grapheme_strlen("\xFF"));
--EXPECT--
1
4
0
bool(false)
