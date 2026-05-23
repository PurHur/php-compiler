--TEST--
stdlib json_last_error() after invalid JSON (issue #1173)
--FILE--
<?php
json_decode('{invalid', true);
echo json_last_error() === 4 ? '4' : 'n', "\n";
json_decode('{"ok":1}', true);
echo json_last_error() === 0 ? '0' : 'n', "\n";
--EXPECT--
4
0
