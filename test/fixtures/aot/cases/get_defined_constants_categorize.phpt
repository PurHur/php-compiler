--TEST--
AOT: get_defined_constants() categorize bool coercion (#4585)
--FILE--
<?php
$flat = get_defined_constants(false);
$cats = get_defined_constants(1);
echo is_array($flat) && is_array($cats) ? "ok\n" : "fail\n";
--EXPECT--
ok
