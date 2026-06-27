--TEST--
VM: similar_text() &$percent after local init (#12690)
--FILE--
<?php
$p = 0.0;
similar_text('hello', 'hallo', $p);
echo $p, "\n";
--EXPECT--
80
