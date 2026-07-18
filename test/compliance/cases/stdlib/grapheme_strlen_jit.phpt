--TEST--
stdlib grapheme_strlen() JIT — compile-time fold (#5914)
--FILE--
<?php
echo (int) function_exists('grapheme_strlen'), "\n";
echo grapheme_strlen('café'), "\n";
echo grapheme_strlen(''), "\n";
--EXPECT--
1
4
0
