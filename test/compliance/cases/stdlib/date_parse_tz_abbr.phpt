--TEST--
stdlib date_parse() tz_abbr for UTC and TIMELIB_ZONETYPE_ABBR (#25486)
--FILE--
<?php
declare(strict_types=1);

$utc = date_parse('2020-01-02 03:04:05 UTC');
echo ($utc['tz_abbr'] ?? 'x'), '|', ($utc['tz_id'] ?? 'x'), '|', ($utc['zone_type'] ?? 'x'), "\n";

$gmt = date_parse('2020-01-02 03:04:05 GMT');
echo ($gmt['tz_abbr'] ?? 'x'), '|', array_key_exists('tz_id', $gmt) ? 'has_id' : 'no_id', '|', ($gmt['zone_type'] ?? 'x'), '|', ($gmt['zone'] ?? 'x'), "\n";

$est = date_parse('2020-01-02 03:04:05 EST');
echo ($est['tz_abbr'] ?? 'x'), '|', ($est['zone_type'] ?? 'x'), '|', ($est['zone'] ?? 'x'), "\n";

$z = date_parse('2020-01-02T03:04:05Z');
echo ($z['tz_abbr'] ?? 'x'), '|', ($z['zone_type'] ?? 'x'), '|', ($z['zone'] ?? 'x'), "\n";

$ny = date_parse('2020-01-02 03:04:05 America/New_York');
echo array_key_exists('tz_abbr', $ny) ? 'has_abbr' : 'no_abbr', '|', ($ny['tz_id'] ?? 'x'), '|', ($ny['zone_type'] ?? 'x'), "\n";
--EXPECT--
UTC|UTC|3
GMT|no_id|2|0
EST|2|-18000
Z|2|0
no_abbr|America/New_York|3
