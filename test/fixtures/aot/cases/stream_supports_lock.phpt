--TEST--
AOT: stream_supports_lock()/file_exists() ternary echo after runtime-bridge bool (issue #19459)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports_lock($fp) ? '1' : '0', "\n";
fclose($fp);
echo file_exists(__FILE__) ? '1' : '0', "\n";
--EXPECT--
1
0
1
