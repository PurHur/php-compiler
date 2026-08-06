--TEST--
stdlib session_status() JIT returns PHP_SESSION_* int (#28203, re-#7321)
--FILE--
<?php
ob_start();
$st = session_status();
echo $st, "\n";
echo $st === PHP_SESSION_NONE ? "none\n" : "bad\n";
session_start();
$st = session_status();
echo $st, "\n";
echo $st === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
--EXPECT--
1
none
2
active
