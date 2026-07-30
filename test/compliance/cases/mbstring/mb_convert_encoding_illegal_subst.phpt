--TEST--
mbstring mb_convert_encoding()/mb_convert_variables() illegal-byte substitution (#25207)
--FILE--
<?php
mb_substitute_character(63);
$before = mb_get_info('illegal_chars');
echo bin2hex(mb_convert_encoding("\x80\x81", 'UTF-8', 'UTF-8')), "\n";
echo mb_get_info('illegal_chars') - $before, "\n";

mb_substitute_character(0xFFFD);
echo bin2hex(mb_convert_encoding("\x80", 'UTF-8', 'UTF-8')), "\n";

mb_substitute_character('none');
var_export(mb_convert_encoding("\x80", 'UTF-8', 'UTF-8'));
echo "\n";

mb_substitute_character(63);
$v = "\x80";
mb_convert_variables('UTF-8', 'UTF-8', $v);
echo bin2hex($v), "\n";

mb_substitute_character('long');
echo mb_convert_encoding('あ', 'ASCII', 'UTF-8'), "\n";
--EXPECT--
3f3f
2
efbfbd
''
3f
U+3042
