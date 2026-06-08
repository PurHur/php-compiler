--TEST--
Web: http_response_code(405) Method Not Allowed
--FILE--
<?php
echo http_response_code(405) ? 'true' : 'false', "\n";
echo http_response_code()->value, "\n";
--EXPECT--
true
405
