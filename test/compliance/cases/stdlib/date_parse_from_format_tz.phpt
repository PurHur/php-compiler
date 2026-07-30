--TEST--
stdlib date_parse_from_format() T timezone zone_type/tz_abbr/tz_id (#25487, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$p = date_parse_from_format('Y-m-d H:i:s T', '2020-01-02 03:04:05 UTC');
echo ($p['zone_type'] ?? 'x'), "\n";
echo ($p['tz_abbr'] ?? 'x'), "\n";
echo ($p['tz_id'] ?? 'x'), "\n";
echo !empty($p['is_localtime']) ? 'local' : 'not_local', "\n";

$est = date_parse_from_format('Y-m-d H:i:s T', '2020-01-02 03:04:05 EST');
echo ($est['zone_type'] ?? 'x'), "\n";
echo ($est['tz_abbr'] ?? 'x'), "\n";
echo ($est['zone'] ?? 'x'), "\n";
echo array_key_exists('tz_id', $est) ? 'has_tz_id' : 'no_tz_id', "\n";
--EXPECT--
3
UTC
UTC
local
2
EST
-18000
no_tz_id
