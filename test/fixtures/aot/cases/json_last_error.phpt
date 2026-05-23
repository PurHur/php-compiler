--TEST--
AOT json_last_error() after invalid JSON (issue #1173)
--FILE--
<?php
json_decode('{invalid', true);
echo json_last_error() === 4 ? '4' : '0', "\n";
--EXPECT--
4
