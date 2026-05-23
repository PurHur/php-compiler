--TEST--
stdlib preg_last_error() after invalid pattern (issue #1181)
--FILE--
<?php
preg_match('not-a-regex', 'x');
echo preg_last_error() === 6 ? '6' : 'n', "\n";
preg_match('/ok/', 'ok');
echo preg_last_error() === 0 ? '0' : 'n', "\n";
--EXPECT--
6
0
