--TEST--
AOT: similar_text() &$percent hello/hallo (#26897)
--FILE--
<?php
similar_text('hello', 'hallo', $p);
echo (int) $p, "\n";
echo similar_text('hello', 'hallo'), "\n";
--EXPECT--
80
4
