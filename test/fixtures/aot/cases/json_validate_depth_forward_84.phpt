--TEST--
AOT json_validate() honors $depth like json_decode (issue #23007)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Literal json + literal depth → compile-time fold via VmJsonValidate (avoids thin-AOT
// NestedJIT / last_error_msg gaps). Bool table matches Zend / VM (#23007).
echo 'flat1=', json_validate('{"a":1}', 1) ? '1' : '0', "\n";
echo 'nest1=', json_validate('{"a":{"b":1}}', 1) ? '1' : '0', "\n";
echo 'flat2=', json_validate('{"a":1}', 2) ? '1' : '0', "\n";
echo 'nest2=', json_validate('{"a":{"b":1}}', 2) ? '1' : '0', "\n";
echo 'flat3=', json_validate('{"a":1}', 3) ? '1' : '0', "\n";
echo 'nest3=', json_validate('{"a":{"b":1}}', 3) ? '1' : '0', "\n";
--EXPECT--
flat1=0
nest1=0
flat2=1
nest2=0
flat3=1
nest3=1
