--TEST--
JIT: similar_text() (#2445)
--FILE--
<?php
echo similar_text('Hello World', 'Hello PHP'), "\n";
echo similar_text('kitten', 'sitting'), "\n";
echo similar_text('abc', 'abc'), "\n";
--EXPECT--
6
4
3
