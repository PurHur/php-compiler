--TEST--
stream_supports() on php://memory (issue #5062, php-src stream capability probe)
--FILE--
<?php
echo function_exists('stream_supports') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo defined('STREAM_META_TOUCH') ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_META_TOUCH) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_LOCK) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_FILTER) ? '1' : '0', "\n";
echo defined('STREAM_META_SEEKABLE') ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_META_SEEKABLE) ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
1
1
0
0
1
1
1
