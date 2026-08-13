--TEST--
AOT: similar_text() &$percent programming/programmer (#30810)
--FILE--
<?php
similar_text('programming', 'programmer', $p);
echo $p, "\n";
echo similar_text('programming', 'programmer'), "\n";
--EXPECT--
76.190476190476
8
