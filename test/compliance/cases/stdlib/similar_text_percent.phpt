--TEST--
JIT: similar_text() &$percent (#3583)
--FILE--
<?php
$p = 0;
similar_text('hello', 'hallo', $p);
echo $p, "\n";
--EXPECT--
80
