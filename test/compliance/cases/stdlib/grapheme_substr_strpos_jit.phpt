--TEST--
stdlib grapheme_substr()/grapheme_strpos() JIT — compile-time fold (#3352)
--FILE--
<?php
$s = "a\xCC\x81b";
echo grapheme_strlen($s), "\n";
echo grapheme_substr($s, 0, 1), "\n";
var_export(grapheme_strpos($s, 'b'));
echo "\n";
--EXPECT--
2
á
1
