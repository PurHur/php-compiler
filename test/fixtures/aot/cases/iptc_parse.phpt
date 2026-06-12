--TEST--
AOT: iptcparse() compile-time literal (#6104)
--FILE--
<?php
echo function_exists('iptcparse') ? "fn\n" : "no-fn\n";
iptcparse("\x1c\x02\x78\x00\x04Test");
echo "ok\n";
--EXPECT--
fn
ok
