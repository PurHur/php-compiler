--TEST--
AOT: unset($_SERVER[$key]) on dynamic string key (issue #1224)
--ENV--
HTTP_X_TEST=1
--FILE--
<?php
$key = 'HTTP_X_TEST';
unset($_SERVER[$key]);
echo isset($_SERVER[$key]) ? 'present' : 'gone', "\n";
--EXPECT--
gone
