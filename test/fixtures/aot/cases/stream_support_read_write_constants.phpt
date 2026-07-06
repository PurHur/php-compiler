--TEST--
AOT: STREAM_SUPPORT_READ/WRITE constants and stream_supports() probes (issue #16846)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('STREAM_SUPPORT_READ') ? '1' : '0', "\n";
echo defined('STREAM_SUPPORT_WRITE') ? '1' : '0', "\n";
$fp = tmpfile();
echo stream_supports($fp, STREAM_SUPPORT_READ) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_SUPPORT_WRITE) ? '1' : '0', "\n";
echo stream_supports($fp, 'read') ? '1' : '0', "\n";
echo stream_supports($fp, 'write') ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
1
1
1
1
1
1
