--TEST--
AOT timezone_name_from_abbr() — abbreviation lookup (#10957, ext/date/php_date.c)
--FILE--
<?php
echo timezone_name_from_abbr('EST', -18000, 0), "\n";
var_export(timezone_name_from_abbr('NOTREAL', -1, -1));
echo "\n";
--EXPECT--
America/New_York
false
