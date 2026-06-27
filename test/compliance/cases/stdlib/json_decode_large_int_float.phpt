--TEST--
stdlib json_decode() oversized integers decode as float (ext/json/php_json.c, #12496)
--FILE--
<?php
echo json_decode('12345678901234567890');
--EXPECT--
1.2345678901235E+19
