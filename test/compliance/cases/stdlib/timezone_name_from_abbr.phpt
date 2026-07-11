--TEST--
stdlib timezone_name_from_abbr() abbreviation lookup (#10957, ext/date/php_date.c)
--FILE--
<?php
echo timezone_name_from_abbr('EST', -18000, 0), "\n";
var_export(timezone_name_from_abbr('est', -18000, 0));
echo "\n";
var_export(timezone_name_from_abbr('EDT', -14400, 1));
echo "\n";
var_export(timezone_name_from_abbr('NOTREAL', -1, -1));
echo "\n";
var_export(timezone_name_from_abbr('gmt'));
echo "\n";
--EXPECT--
America/New_York
'America/New_York'
'America/New_York'
false
'UTC'
