--TEST--
AOT preg_last_error() after invalid pattern
--FILE--
<?php
preg_match('bad', 'x');
echo preg_last_error() === 6 ? '6' : '0', "\n";
--EXPECT--
6
