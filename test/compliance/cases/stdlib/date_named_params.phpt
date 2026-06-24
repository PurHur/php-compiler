--TEST--
stdlib date() format:/timestamp: named parameters (#9647, ext/date/php_date.stub.php)
--FILE--
<?php
date_default_timezone_set('UTC');
var_export(date(format: 'Y-m-d', timestamp: 0));
echo "\n";
var_export(date(timestamp: 0, format: 'Y'));
echo "\n";
var_export(date('Y-m-d', 0));
echo "\n";
--EXPECT--
'1970-01-01'
'1970'
'1970-01-01'
