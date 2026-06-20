--TEST--
AOT: ob_end_clean() no active buffer emits Notice (issue #10260)
--FILE--
<?php
$ok = ob_end_clean();
$last = error_get_last();
echo ($last['message'] ?? 'null'), "\n";
echo $ok ? 't' : 'f', "\n";
ob_start();
echo 'y';
echo ob_end_clean() ? 't' : 'f', "\n";
--EXPECT--
ob_end_clean(): Failed to delete buffer. No buffer to delete
f
t
