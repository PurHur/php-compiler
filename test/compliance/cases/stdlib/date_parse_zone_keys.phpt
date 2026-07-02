--TEST--
stdlib date_parse() numeric offset zone_type/zone/is_dst keys (#14806, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$r = date_parse('2024-01-01T12:00:00+02:00');
echo ($r['zone_type'] ?? 'x'), "\n";
echo ($r['zone'] ?? 'x'), "\n";
echo false === ($r['is_dst'] ?? null) ? 'is_dst_false' : 'is_dst_set', "\n";

$r2 = date_parse('2024-01-01T12:00:00Z');
echo ($r2['zone_type'] ?? 'x'), "\n";
echo ($r2['zone'] ?? 'x'), "\n";
--EXPECT--
1
7200
is_dst_false
2
0
