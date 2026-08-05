--TEST--
AOT: similar_text() &$percent on undefined variable (issue #10403, #26897)
--FILE--
<?php
similar_text('hello', 'hell', $pct);
// Avoid AOT round() — assert Zend percent 800/9 ≈ 88.888… (#26897 NestedJIT-safe helper).
echo ($pct > 88.88 && $pct < 88.9) ? "ok\n" : ("fail=" . $pct . "\n");
--EXPECT--
ok
