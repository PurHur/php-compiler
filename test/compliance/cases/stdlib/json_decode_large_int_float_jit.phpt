--TEST--
stdlib json_decode() oversized integers JIT decode as float (#12496)
--FILE--
<?php
echo json_decode('12345678901234567890');
--EXPECT--
1.2345678901235E+19
