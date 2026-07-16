--TEST--
AOT: stream_supports_lock() on tmpfile/fopen matches VM (issue #19462, re-#17737)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports_lock($fp) ? '1' : '0', "\n";
fclose($fp);
echo stream_supports_lock(tmpfile()) ? '1' : '0', "\n";
$rf = fopen(__FILE__, 'r');
echo stream_supports_lock($rf) ? '1' : '0', "\n";
fclose($rf);
echo file_exists(__FILE__) ? '1' : '0', "\n";
--EXPECT--
1
0
1
1
1
