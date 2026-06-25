--TEST--
STREAM_SUPPORT_* constants and stream_supports() probes (issue #11702)
--FILE--
<?php
echo defined('STREAM_SUPPORT_LOCK') ? '1' : '0', "\n";
echo defined('STREAM_SUPPORT_SEEK') ? '1' : '0', "\n";
echo defined('STREAM_SUPPORT_TELL') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports($fp, STREAM_SUPPORT_LOCK) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_SUPPORT_SEEK) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_SUPPORT_TELL) ? '1' : '0', "\n";
?>
--EXPECT--
1
1
1
0
1
1
