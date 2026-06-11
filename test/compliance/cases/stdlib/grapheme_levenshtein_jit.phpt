--TEST--
stdlib grapheme_levenshtein() JIT — compile-time fold (#6998)
--FILE--
<?php
echo (int) function_exists('grapheme_levenshtein'), "\n";
echo grapheme_levenshtein('kitten', 'sitting'), "\n";
echo grapheme_levenshtein('café', 'café'), "\n";
--EXPECT--
1
3
0
