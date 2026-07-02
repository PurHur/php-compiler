--TEST--
date_parse_from_format() fraction false without fractional format token (#14808, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$r = date_parse_from_format('Y-m-d', '2024-01-01');
echo var_export($r['fraction'], true), "\n";
echo false === $r['fraction'] ? 'false_ok' : 'not_false', "\n";

$r2 = date_parse_from_format('Y-m-d H:i:s.u', '2024-01-01 12:00:00.123456');
echo var_export($r2['fraction'], true), "\n";
echo is_float($r2['fraction']) ? 'float_ok' : 'not_float', "\n";
--EXPECT--
false
false_ok
0.123456
float_ok
