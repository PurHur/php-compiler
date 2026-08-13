--TEST--
AOT: mb_chr()/mb_ord() UTF-8 euro + invalid codepoint (#30759, php-src ext/mbstring/mbstring.c)
--FILE--
<?php
// Avoid bin2hex() — UTF-8 multi-byte AOT segfault is a separate defect; dechex+ord matches Zend bytes.
$s = mb_chr(0x20AC);
echo dechex(ord($s[0])), dechex(ord($s[1])), dechex(ord($s[2])), ' ', mb_ord('€'), "\n";
$s2 = mb_chr(0x20AC, 'UTF-8');
echo dechex(ord($s2[0])), dechex(ord($s2[1])), dechex(ord($s2[2])), ' ', mb_ord('€', 'UTF-8'), "\n";
echo mb_chr(65), ' ', mb_ord('A'), "\n";
var_export(mb_chr(-1));
echo "\n";
var_export(mb_chr(0x110000));
echo "\n";
--EXPECT--
e282ac 8364
e282ac 8364
A 65
false
false
