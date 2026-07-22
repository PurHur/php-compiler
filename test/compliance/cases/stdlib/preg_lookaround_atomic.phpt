--TEST--
stdlib preg lookaround + atomic groups (issue #22002, ext/pcre / VmPregEngine)
--FILE--
<?php
echo (int) preg_match('/foo(?=bar)/', 'foobar'), "\n";
echo (int) preg_match('/foo(?=bar)/', 'foobaz'), "\n";
echo (int) preg_match('/foo(?!bar)/', 'foobaz'), "\n";
echo (int) preg_match('/foo(?!bar)/', 'foobar'), "\n";
echo (int) preg_match('/(?<=foo)bar/', 'foobar'), "\n";
echo (int) preg_match('/(?<=foo)bar/', 'fooxbar'), "\n";
echo (int) preg_match('/(?<!foo)bar/', 'fooxbar'), "\n";
echo (int) preg_match('/(?<!foo)bar/', 'foobar'), "\n";
echo (int) preg_match('/(?>a+)b/', 'aaab'), "\n";
echo (int) preg_match('/(?>a+)a/', 'aaa'), "\n";
preg_match('/(?<=a)(b)/', 'ab', $m);
echo isset($m[1]) ? $m[1] : 'missing', "\n";
--EXPECT--
1
0
1
0
1
0
1
0
1
0
b
