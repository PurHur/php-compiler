--TEST--
stdlib filter_var() validator flags (#13189, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('tëst@example.com', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_FLAG_EMAIL_UNICODE]));
echo "\n";
var_export(filter_var('tëst@example.com', FILTER_VALIDATE_EMAIL));
echo "\n";
var_export(filter_var('http://example.com', FILTER_VALIDATE_URL, ['flags' => FILTER_FLAG_PATH_REQUIRED]));
echo "\n";
var_export(filter_var('http://example.com/path', FILTER_VALIDATE_URL, ['flags' => FILTER_FLAG_PATH_REQUIRED]));
echo "\n";
var_export(filter_var('10.0.0.1', FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_NO_PRIV_RANGE]));
echo "\n";
var_export(filter_var('8.8.8.8', FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_NO_PRIV_RANGE]));
echo "\n";
--EXPECT--
'tëst@example.com'
false
false
'http://example.com/path'
false
'8.8.8.8'
