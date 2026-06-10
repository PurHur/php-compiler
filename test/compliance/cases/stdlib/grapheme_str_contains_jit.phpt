--TEST--
stdlib grapheme_str_contains() JIT — grapheme cluster search (#7128)
--FILE--
<?php
echo (int) function_exists('grapheme_str_contains'), "\n";
echo grapheme_str_contains('hello', 'ell') ? 'y' : 'n', "\n";
echo grapheme_str_contains('hello', 'z') ? 'y' : 'n', "\n";
echo grapheme_str_contains('', '') ? 'y' : 'n', "\n";
echo grapheme_str_contains('café', 'é') ? 'y' : 'n', "\n";
--EXPECT--
1
y
n
y
y
