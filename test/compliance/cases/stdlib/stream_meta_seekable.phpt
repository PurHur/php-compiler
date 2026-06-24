--TEST--
STREAM_META_SEEKABLE constant and stream_supports() seek probe (PHP 8.4, issue #11131)
--FILE--
<?php
echo defined('STREAM_META_SEEKABLE') ? '1' : '0', "\n";
echo STREAM_META_SEEKABLE, "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports($fp, STREAM_META_SEEKABLE) ? '1' : '0', "\n";
$stdin = fopen('php://stdin', 'r');
echo stream_supports($stdin, STREAM_META_SEEKABLE) ? '1' : '0', "\n";
fclose($fp);
fclose($stdin);
--EXPECT--
1
8
1
0
