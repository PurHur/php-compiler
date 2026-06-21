--TEST--
stdlib similar_text() &$percent on undefined variable — no E_WARNING (issue #10403, ext/standard/string.c)
--FILE--
<?php
similar_text('hello', 'hell', $pct);
var_dump(round($pct, 2));
--EXPECT--
float(88.89)
