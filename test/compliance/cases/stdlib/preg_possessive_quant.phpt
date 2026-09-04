--TEST--
stdlib preg possessive quantifiers ++ / *+ / ?+ (issue #36380, ext/pcre / VmPregEngine)
--FILE--
<?php
echo (int) preg_match('/a++/', 'aaa'), "\n";
echo (int) preg_match('/a++a/', 'aaa'), "\n";
echo (int) preg_match('/(?>a+)a/', 'aaa'), "\n";
echo (int) preg_match('/a*+b/', 'aaab'), "\n";
echo (int) preg_match('/a*+a/', 'aaa'), "\n";
echo (int) preg_match('/a?+a/', 'aa'), "\n";
$m = null;
echo (int) preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', 'a|b|c', $m), "\n";
echo isset($m[0]) ? count($m[0]) : 'missing', "\n";
$empty = null;
echo (int) preg_match_all('/xyz/', 'abc', $empty), "\n";
echo isset($empty[0]) && is_array($empty[0]) ? 'ok' : 'bad', "\n";
--EXPECT--
1
0
0
1
0
1
3
3
0
ok
