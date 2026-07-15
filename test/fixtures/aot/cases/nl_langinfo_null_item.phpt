--TEST--
AOT: nl_langinfo(null) — Z_PARAM_LONG coerce to 0, warn, return false (#19025, ext/standard/nl_langinfo.c)
--FILE--
<?php
$r = nl_langinfo(null);
echo ($r === false ? 'false' : $r), "\n";
--EXPECT--
false
--EXPECT_EXIT--
0
