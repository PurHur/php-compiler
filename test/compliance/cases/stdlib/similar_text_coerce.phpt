--TEST--
stdlib similar_text() — scalar-to-string coercion and &$percent (#4543, ext/standard/string.c)
--FILE--
<?php
echo similar_text(123, '123'), "\n";
echo similar_text(12.5, '12.5'), "\n";

$p = 0.0;
similar_text('hello', 'hello world', $p);
echo $p, "\n";
--EXPECT--
3
4
62.5
