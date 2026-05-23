--TEST--
stdlib json_last_error() JIT after invalid JSON (issue #1173)
--FILE--
<?php
json_decode('{invalid', true);
echo json_last_error() === 4 ? '1' : '0', "\n";
--EXPECT--
1
