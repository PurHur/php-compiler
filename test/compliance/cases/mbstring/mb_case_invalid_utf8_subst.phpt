--TEST--
mbstring case builtins substitute illegal UTF-8 like Zend (#28629, ext/mbstring/php_unicode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$s = "\xE9cole";
echo bin2hex(mb_ucfirst($s, "UTF-8")), "\n";
echo bin2hex(mb_lcfirst($s, "UTF-8")), "\n";
echo bin2hex(mb_strtoupper($s, "UTF-8")), "\n";
echo bin2hex(mb_strtolower($s, "UTF-8")), "\n";
echo bin2hex(mb_convert_case($s, MB_CASE_TITLE, "UTF-8")), "\n";
echo bin2hex(mb_scrub($s, "UTF-8")), "\n";
echo mb_strlen($s, "UTF-8"), "\n";
echo bin2hex(mb_substr($s, 0, 1, "UTF-8")), "\n";
$s2 = "a\xE9b";
echo bin2hex(mb_ucfirst($s2, "UTF-8")), "\n";
echo bin2hex(mb_lcfirst($s2, "UTF-8")), "\n";
mb_substitute_character(0xFFFD);
echo bin2hex(mb_strtoupper($s, "UTF-8")), "\n";
echo bin2hex(mb_ucfirst($s, "UTF-8")), "\n";
--EXPECT--
e9636f6c65
e9636f6c65
3f434f4c45
3f636f6c65
3f436f6c65
3f636f6c65
5
3f
413f62
61e962
efbfbd434f4c45
e9636f6c65
