--TEST--
Web: http_response_code(405) Method Not Allowed
--FILE--
<?php
var_export(http_response_code(405));
echo "\n";
var_export(http_response_code());
--EXPECT--
405
405
