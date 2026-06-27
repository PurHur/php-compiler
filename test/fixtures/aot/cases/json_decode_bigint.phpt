--TEST--
AOT: json_decode() oversized integer float + JSON_BIGINT_AS_STRING (#12496, #12495)
--FILE--
<?php
echo json_decode('12345678901234567890'), "\n";
var_export(json_decode('9999999999999999999', false, 512, JSON_BIGINT_AS_STRING));
--EXPECT--
1.2345678901235E+19
'9999999999999999999'
