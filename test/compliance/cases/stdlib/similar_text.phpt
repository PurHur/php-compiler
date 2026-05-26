--TEST--
stdlib similar_text() (#2445)
--FILE--
<?php
echo similar_text('Hello World', 'Hello PHP'), "\n";
similar_text('Hello World', 'Hello PHP', $p);
echo $p, "\n";
echo similar_text('kitten', 'sitting'), "\n";
similar_text('kitten', 'sitting', $p2);
echo $p2, "\n";
echo similar_text('abc', 'abc'), "\n";
similar_text('abc', 'abc', $p3);
echo $p3, "\n";
echo similar_text('', ''), "\n";
similar_text('', '', $p4);
echo $p4, "\n";
echo similar_text('first', 'second'), "\n";
similar_text('first', 'second', $p5);
echo $p5, "\n";
--EXPECT--
6
60
4
61.538461538462
3
100
0
0
1
18.181818181818
