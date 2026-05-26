--TEST--
stdlib similar_text() (#2445)
--FILE--
<?php
echo similar_text('', ''), "\n";
$p = 0.0;
echo similar_text('bafoobar', 'barfoo', $p), "\n";
echo $p, "\n";
$p = 0.0;
echo similar_text('barfoo', 'bafoobar', $p), "\n";
echo $p, "\n";
echo similar_text('kitten', 'sitting'), "\n";
echo similar_text('abc', 'abc'), "\n";
--EXPECT--
0
5
71.428571428571
3
42.857142857143
4
3
