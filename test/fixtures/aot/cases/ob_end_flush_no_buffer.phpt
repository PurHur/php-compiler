--TEST--
AOT: ob_end_flush() no active buffer emits Notice (issue #10536)
--FILE--
<?php
$ok = ob_end_flush();
$last = error_get_last();
echo ($last['message'] ?? 'null'), "\n";
echo $ok ? 't' : 'f', "\n";
ob_start();
echo 'y';
echo ob_end_flush() ? 't' : 'f', "\n";
--EXPECT--
ob_end_flush(): Failed to delete and flush buffer. No buffer to delete or flush
f
yt
