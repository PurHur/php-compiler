--TEST--
AOT: similar_text() &$percent on undefined variable (issue #10403)
--FILE--
<?php
similar_text('hello', 'hell', $pct);
var_dump(round($pct, 2));
--EXPECT--
float(88.89)
