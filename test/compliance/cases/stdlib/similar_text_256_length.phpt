--TEST--
stdlib similar_text() accepts 256-byte operands (#18543, ext/standard/string.c)
--FILE--
<?php
$s256 = str_repeat('a', 256);
$p = 0.0;
echo similar_text($s256, $s256, $p), "\n";
echo $p, "\n";
--EXPECT--
256
100
