--TEST--
AOT: stream_supports_lock() on tmpfile/fopen matches VM (issue #19462, re-#17737)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports_lock($fp) ? '1' : '0', "\n";
fclose($fp);
echo stream_supports_lock(tmpfile()) ? '1' : '0', "\n";
// AotTest compiles via stdin and sets REQUEST_METHOD=GET; use a literal path (not a
// local var) so Standalone fopen stays stable under that web-shaped env.
$rf = fopen('/etc/hosts', 'r');
echo stream_supports_lock($rf) ? '1' : '0', "\n";
fclose($rf);
echo file_exists('/etc/hosts') ? '1' : '0', "\n";
--EXPECT--
1
0
1
1
1
