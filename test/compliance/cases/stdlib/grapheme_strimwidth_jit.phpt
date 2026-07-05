--TEST--
stdlib grapheme_strimwidth() JIT — compile-time fold (#9793)
--FILE--
<?php
echo grapheme_strimwidth('hello', 0, 10), "\n";
echo grapheme_strimwidth('こんにちは', 0, 3, '...'), "\n";
--EXPECT--
hello
...
