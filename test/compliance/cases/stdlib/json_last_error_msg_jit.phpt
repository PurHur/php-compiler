--TEST--
stdlib json_last_error_msg() JIT after invalid JSON (issue #3175)
--FILE--
<?php
json_decode('{invalid', true);
echo json_last_error_msg(), "\n";
--EXPECT--
Syntax error
