--TEST--
AOT date_create_from_format/date_parse — compile-time format parsing (#6172)
--ENV--
TZ=UTC
--FILE--
<?php
$r = date_create_from_format('Y-m-d', '2024-06-05');
echo $r->format('Y-m-d'), "\n";
$p = date_parse('2024-01-15 12:00:00');
echo (string) $p['year'], "\n";
$p2 = date_parse_from_format('Y-m-d', '2024-06-05');
echo (string) $p2['year'], "\n";
$ri = date_create_immutable_from_format('Y-m-d H:i:s', '2024-01-15 12:00:00');
echo $ri->format('Y-m-d H:i:s'), "\n";
--EXPECT--
2024-06-05
2024
2024
2024-01-15 12:00:00
