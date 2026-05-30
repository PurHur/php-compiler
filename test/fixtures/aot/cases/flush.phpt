--TEST--
AOT: flush() with output buffering (issue #3388)
--FILE--
<?php
echo function_exists('flush') ? '1' : '0', "\n";
ob_start();
echo 'buf';
flush();
echo 'end';
ob_end_flush();
--EXPECT--
1
bufend
