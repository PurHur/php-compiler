--TEST--
stdlib ResponseCode::TooEarly — forward PHP 8.4 profile (#18059, http_status_codes.h)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(ResponseCode::TooEarly->name);
echo "\n";
var_export(ResponseCode::TooEarly->value);
echo "\n";
var_export(ResponseCode::TooEarly === HTTP_TOO_EARLY);
echo "\n";
--EXPECT--
'TooEarly'
425
false
