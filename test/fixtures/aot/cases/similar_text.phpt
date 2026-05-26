--TEST--
AOT: similar_text() (#2445)
--FILE--
<?php
echo similar_text('kitten', 'sitting'), "\n";
$p = 0.0;
echo similar_text('bafoobar', 'barfoo', $p), "\n";
echo $p, "\n";
--EXPECT--
4
5
71.4286
