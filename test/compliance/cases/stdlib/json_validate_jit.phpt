--TEST--
stdlib json_validate() JIT — valid and invalid JSON (issue #3101)
--FILE--
<?php
echo json_validate('{"a":1}') ? '1' : '0';
echo "\n";
echo json_validate('{') ? '1' : '0';
echo "\n";
--EXPECT--
1
0
