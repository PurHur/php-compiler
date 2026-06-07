--TEST--
stdlib unlink() VM bootstrap without ext/ffi (#7314)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_unlink_boot_');
echo file_exists($path) ? "exists\n" : "missing\n";
echo unlink($path) ? "removed\n" : "fail\n";
echo file_exists($path) ? "still\n" : "gone\n";
echo unlink($path) ? "again\n" : "nogone\n";
--EXPECT--
exists
removed
gone
nogone
