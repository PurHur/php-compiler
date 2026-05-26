--TEST--
JIT: similar_text() (#2445)
--FILE--
<?php
echo similar_text('bafoobar', 'barfoo'), "\n";
echo similar_text('kitten', 'sitting'), "\n";
echo similar_text('abc', 'abc'), "\n";
--EXPECT--
5
4
3
