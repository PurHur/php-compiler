--TEST--
stdlib grapheme_strimwidth() JIT — compile-time fold (#9793, #17342)
--FILE--
<?php
echo grapheme_strimwidth('hello', 0, 10), "\n";
echo grapheme_strimwidth('日本語テスト', 0, 4, 'UTF-8'), "\n";
--EXPECT--
hello
日本
