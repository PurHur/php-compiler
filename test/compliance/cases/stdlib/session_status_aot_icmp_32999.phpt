--TEST--
stdlib session_status() AOT icmp uses i8 activeGlobal (#32999)
--FILE--
<?php
$st1 = session_status();
session_start();
$st2 = session_status();
echo $st1, "\n";
echo $st1 === PHP_SESSION_NONE ? "none\n" : "bad\n";
echo $st2, "\n";
echo $st2 === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
--EXPECT--
1
none
2
active
