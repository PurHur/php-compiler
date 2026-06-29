--TEST--
stdlib date_parse() timezone suffix + English absolute dates (#13405, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$r = date_parse('2020-01-01 12:00:00 UTC');
$r2 = date_parse('January 1, 2020');

echo ($r['year'] ?? 'x')."\n";
echo ($r['tz_id'] ?? 'x')."\n";
echo ($r['error_count'] ?? 'x')."\n";
echo ($r2['year'] ?? 'x')."\n";
echo ($r2['error_count'] ?? 'x')."\n";
echo false === ($r2['hour'] ?? null) ? 'hour_false' : 'hour_set';
echo "\n";
--EXPECT--
2020
UTC
0
2020
0
hour_false
